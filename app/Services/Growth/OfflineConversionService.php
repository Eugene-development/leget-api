<?php

declare(strict_types=1);

namespace App\Services\Growth;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class OfflineConversionService
{
    private const API = 'https://api-metrika.yandex.net/management/v1/counter/';

    /** Persist confirmed milestones before any network call. No contact or form content is exported. */
    public function collect(?string $site = null): int
    {
        $count = 0;
        foreach (DB::table('growth_metrika_settings')->where('enabled', true)->when($site, fn ($q) => $q->where('license_id', $site))->get() as $settings) {
            DB::table('crm_deals')->where('license_id', $settings->license_id)->whereNotNull('signed_at')->orderBy('id')->chunkById(100, function ($deals) use ($settings, &$count) {
                foreach ($deals as $deal) {
                    if (! $deal->contract_number || ! $deal->contract_date) {
                        continue;
                    }
                    $request = DB::table('service_requests')->where('license_id', $settings->license_id)->where('service_type', '!=', 'manager-access')->where('crm_deal_id', $deal->id)->orderBy('created_at')->orderBy('id')->first();
                    if (! $request) {
                        continue;
                    }
                    $attribution = app(InsightsService::class)->attribution($request);
                    $identifier = isset($attribution['yclid']) && preg_match('/^\d{1,128}$/D', (string) $attribution['yclid']) ? ['Yclid', (string) $attribution['yclid']]
                        : (isset($attribution['metrika_client_id']) && preg_match('/^\d{1,128}$/D', (string) $attribution['metrika_client_id']) ? ['ClientId', (string) $attribution['metrika_client_id']] : null);
                    if (! $identifier) {
                        continue;
                    }
                    $signed = null;
                    foreach (DB::table('crm_events')->where('license_id', $settings->license_id)->where('entity_type', 'deals')->where('entity_id', $deal->id)->where('action', 'updated')->orderBy('created_at')->orderBy('id')->get() as $event) {
                        $data = json_decode($event->data, true) ?: [];
                        if (! empty($data['after']['signed_at']) && empty($data['before']['signed_at'])) {
                            $signed = $event->created_at;
                            break;
                        }
                    }
                    // Existing dated contracts cannot acquire a new conversion time from an unrelated edit.
                    $signed ??= CarbonImmutable::parse($deal->signed_at, config('app.timezone'))->startOfDay()->format('Y-m-d H:i:s');
                    $net = '0.00';
                    $paid = null;
                    foreach (DB::table('crm_payments')->where('license_id', $settings->license_id)->where('deal_id', $deal->id)->orderBy('created_at')->orderBy('id')->get() as $payment) {
                        $net = $payment->kind === 'refund' ? bcsub($net, (string) $payment->amount, 2) : bcadd($net, (string) $payment->amount, 2);
                        $paid = bccomp((string) $deal->amount, '0', 2) > 0 && bccomp($net, (string) $deal->amount, 2) >= 0 ? ($paid ?: $payment->created_at) : null;
                    }
                    foreach (['signed' => $signed, 'paid' => $paid] as $milestone => $occurred) {
                        $goal = $settings->{$milestone.'_goal'};
                        if (! $occurred || ! $goal) {
                            continue;
                        }
                        $at = CarbonImmutable::parse($occurred, config('app.timezone'));
                        if ($at->isFuture() || $at->lt(CarbonImmutable::parse($settings->enabled_at, config('app.timezone')))) {
                            continue;
                        }
                        $count += DB::table('growth_metrika_deliveries')->insertOrIgnore([
                            'id' => (string) Str::ulid(), 'license_id' => $settings->license_id, 'deal_id' => $deal->id, 'milestone' => $milestone,
                            'counter_id' => $settings->counter_id, 'goal' => $goal,
                            'payload' => json_encode(['identifier' => $identifier[0], 'value' => $identifier[1], 'timestamp' => $at->timestamp, 'price' => $milestone === 'paid' ? $deal->amount : 0, 'currency' => $deal->currency], JSON_THROW_ON_ERROR),
                            'status' => 'queued', 'retry_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            });
        }

        return $count;
    }

    public function deliver(?string $site = null, int $limit = 100): int
    {
        // A killed upload worker may have transmitted bytes. Never blindly re-upload it.
        DB::table('growth_metrika_deliveries')->where('status', 'sending')->where('locked_at', '<', now()->subMinutes(5))
            ->when($site, fn ($q) => $q->where('license_id', $site))->update(['status' => 'uncertain', 'error' => 'upload_outcome_unknown', 'lock_token' => null, 'locked_at' => null, 'updated_at' => now()]);
        $ids = DB::table('growth_metrika_deliveries')->whereIn('status', ['queued', 'uploaded'])->where('retry_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('locked_at')->orWhere('locked_at', '<', now()->subMinutes(5)))
            ->when($site, fn ($q) => $q->where('license_id', $site))->orderBy('created_at')->limit($limit)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            if ($this->send($id)) {
                $count++;
            }
        }

        return $count;
    }

    private function send(string $id): bool
    {
        $claim = DB::transaction(function () use ($id) {
            $row = DB::table('growth_metrika_deliveries')->where('id', $id)->lockForUpdate()->first();
            if (! $row || ! in_array($row->status, ['queued', 'uploaded'], true) || ($row->locked_at && CarbonImmutable::parse($row->locked_at)->gt(now()->subMinutes(5)))) {
                return null;
            }
            $settings = DB::table('growth_metrika_settings')->where('license_id', $row->license_id)->where('enabled', true)->first();
            if (! $settings || $settings->counter_id !== $row->counter_id) {
                return null;
            }
            $token = (string) Str::uuid();
            DB::table('growth_metrika_deliveries')->where('id', $id)->update(['status' => $row->upload_id ? 'uploaded' : 'sending', 'locked_at' => now(), 'lock_token' => $token, 'attempts' => $row->attempts + 1, 'updated_at' => now()]);

            return [$row, $settings, $token];
        }, 3);
        if (! $claim) {
            return false;
        }
        [$row, $settings, $token] = $claim;
        $update = ['locked_at' => null, 'lock_token' => null, 'updated_at' => now()];
        try {
            $http = Http::acceptJson()->withHeaders(['Authorization' => 'OAuth '.Crypt::decryptString($settings->oauth_token)])->connectTimeout(5)->timeout(20);
            if ($row->upload_id) {
                $response = $http->get(self::API.$row->counter_id.'/offline_conversions/uploading/'.$row->upload_id);
            } else {
                $response = $http->attach('file', $this->csv($row), 'leget'.$row->id.'.csv', ['Content-Type' => 'text/csv'])
                    ->post(self::API.$row->counter_id.'/offline_conversions/upload?comment=LEGET'.$row->id);
            }
            if (! $response->successful()) {
                $status = $response->status();
                $retryablePoll = $status === 429 || $status >= 500;
                $update += ['status' => $row->upload_id ? ($retryablePoll ? 'uploaded' : 'failed') : ($status === 429 ? 'queued' : ($status >= 500 ? 'uncertain' : 'failed')),
                    'error' => 'metrika_http_'.$status, 'retry_at' => now()->addMinutes(min(360, 2 ** min(8, $row->attempts + 1)))];
            } else {
                $upload = $response->json('uploading');
                if (! is_array($upload) || ! preg_match('/^\d+$/D', (string) ($upload['id'] ?? ''))) {
                    $update += ['status' => $row->upload_id ? 'uploaded' : 'uncertain', 'error' => 'invalid_provider_response', 'retry_at' => now()->addMinutes(10)];
                } else {
                    $provider = (string) ($upload['status'] ?? 'UPLOADED');
                    $complete = $provider === 'PROCESSED';
                    $failed = $provider === 'LINKAGE_FAILURE' || ($complete && isset($upload['line_quantity']) && (int) $upload['line_quantity'] < 1);
                    $update += ['upload_id' => (string) $upload['id'], 'provider_status' => mb_substr($provider, 0, 40), 'status' => $failed ? 'failed' : ($complete ? 'delivered' : 'uploaded'),
                        'error' => $failed ? 'identifier_not_matched' : null, 'delivered_at' => $complete && ! $failed ? now() : null, 'retry_at' => now()->addMinutes(10)];
                }
            }
        } catch (ConnectionException) {
            $update += ['status' => $row->upload_id ? 'uploaded' : 'uncertain', 'error' => $row->upload_id ? 'metrika_unavailable' : 'upload_outcome_unknown', 'retry_at' => now()->addMinutes(10)];
        } catch (\Throwable) {
            $update += ['status' => $row->upload_id ? 'uploaded' : 'failed', 'error' => 'delivery_configuration_error', 'retry_at' => now()->addMinutes(10)];
        }
        DB::table('growth_metrika_deliveries')->where('id', $id)->where('lock_token', $token)->update($update);

        return true;
    }

    public function csv(object $row): string
    {
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, [$payload['identifier'], 'Target', 'DateTime', 'Price', 'Currency'], ',', '"', '');
        fputcsv($stream, [$payload['value'], $row->goal, $payload['timestamp'], $payload['price'], $payload['currency']], ',', '"', '');
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }
}
