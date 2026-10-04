<?php

declare(strict_types=1);

namespace App\Services\Growth;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class InsightsService
{
    /** Cohort = requests received in range; each deal belongs to its earliest request. */
    public function report(string $site, string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from, 'Europe/Moscow')->startOfDay()->setTimezone(config('app.timezone'));
        $end = CarbonImmutable::parse($to, 'Europe/Moscow')->endOfDay()->setTimezone(config('app.timezone'));
        $sources = [];
        $requests = DB::table('service_requests')->where('license_id', $site)
            ->where('service_type', '!=', 'manager-access')->whereBetween('created_at', [$start, $end])->orderBy('created_at')->orderBy('id')
            ->get(['id', 'crm_deal_id', 'channel', 'details', 'source_url']);
        $first = DB::table('service_requests as current')->where('current.license_id', $site)->where('current.service_type', '!=', 'manager-access')
            ->whereNotNull('current.crm_deal_id')->whereBetween('current.created_at', [$start, $end])->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('service_requests as older')->whereColumn('older.license_id', 'current.license_id')
                    ->whereColumn('older.crm_deal_id', 'current.crm_deal_id')->where('older.service_type', '!=', 'manager-access')
                    ->where(fn ($q) => $q->whereColumn('older.created_at', '<', 'current.created_at')->orWhere(fn ($q) => $q->whereColumn('older.created_at', 'current.created_at')->whereColumn('older.id', '<', 'current.id')));
            })->pluck('current.id', 'current.crm_deal_id')->all();
        $deals = DB::table('crm_deals')->where('license_id', $site)->whereIn('id', array_keys($first))->get()->keyBy('id');
        $manual = DB::table('crm_deals')->where('license_id', $site)->whereBetween('created_at', [$start, $end])->whereNotExists(function ($q) {
            $q->selectRaw('1')->from('service_requests')->whereColumn('service_requests.license_id', 'crm_deals.license_id')
                ->whereColumn('service_requests.crm_deal_id', 'crm_deals.id')->where('service_requests.service_type', '!=', 'manager-access');
        })->get()->keyBy('id');
        $payments = DB::table('crm_payments')->where('license_id', $site)->whereIn('deal_id', $deals->keys()->merge($manual->keys()))->get(['deal_id', 'kind', 'amount'])->groupBy('deal_id');
        foreach ($requests as $request) {
            $source = $this->source($request);
            $key = json_encode($source, JSON_THROW_ON_ERROR);
            $sources[$key] ??= $source + $this->emptyMetrics();
            $sources[$key]['requests']++;
            if ($request->crm_deal_id && ($first[$request->crm_deal_id] ?? null) === $request->id && isset($deals[$request->crm_deal_id])) {
                $this->addDeal($sources[$key], $deals[$request->crm_deal_id], $payments->get($request->crm_deal_id, collect()));
            }
        }
        // Standalone deals are visible, but never counted as site requests.
        foreach ($manual as $deal) {
            $source = ['source' => 'manual', 'medium' => (string) $deal->source, 'campaign' => ''];
            $key = json_encode($source, JSON_THROW_ON_ERROR);
            $sources[$key] ??= $source + $this->emptyMetrics();
            $this->addDeal($sources[$key], $deal, $payments->get($deal->id, collect()));
        }
        $total = $this->emptyMetrics();
        foreach ($sources as &$source) {
            foreach ($total as $field => $value) {
                $total[$field] += $source[$field];
            }
            $source['conversion'] = $source['requests'] ? round(100 * $source['deals'] / $source['requests'], 1) : null;
        }
        unset($source);
        usort($sources, fn ($a, $b) => $b['net_paid'] <=> $a['net_paid'] ?: $b['requests'] <=> $a['requests']);

        return ['from' => $from, 'to' => $to, 'totals' => $total, 'sources' => array_values($sources),
            'method' => 'request_cohort_first_touch', 'generated_at' => now()->toIso8601String()];
    }

    public function attribution(object $request): array
    {
        $details = json_decode((string) ($request->details ?? '{}'), true) ?: [];
        $attribution = is_array($details['attribution'] ?? null) ? $details['attribution'] : [];
        parse_str((string) (parse_url((string) ($request->source_url ?? ''), PHP_URL_QUERY) ?: ''), $query);
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'yclid'] as $key) {
            if (! isset($attribution[$key]) && isset($query[$key]) && is_string($query[$key])) {
                $attribution[$key] = $query[$key];
            }
        }

        return $attribution;
    }

    private function source(object $request): array
    {
        $data = $this->attribution($request);

        return ['source' => $this->text($data['utm_source'] ?? '') ?: ($request->channel === 'online' ? 'unknown' : $request->channel),
            'medium' => $this->text($data['utm_medium'] ?? ''), 'campaign' => $this->text($data['utm_campaign'] ?? '')];
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? mb_substr(preg_replace('/[\x00-\x1f]/', '', mb_convert_encoding($value, 'UTF-8', 'UTF-8')), 0, 120) : '';
    }

    private function emptyMetrics(): array
    {
        return ['requests' => 0, 'deals' => 0, 'signed' => 0, 'paid_orders' => 0, 'contract_amount' => 0.0, 'payments' => 0.0, 'refunds' => 0.0, 'net_paid' => 0.0];
    }

    private function addDeal(array &$metrics, object $deal, iterable $payments): void
    {
        $metrics['deals']++;
        if ($deal->signed_at) {
            $metrics['signed']++;
            $metrics['contract_amount'] += (float) $deal->amount;
        }
        $net = 0.0;
        foreach ($payments as $payment) {
            $amount = (float) $payment->amount;
            $metrics[$payment->kind === 'refund' ? 'refunds' : 'payments'] += $amount;
            $net += $payment->kind === 'refund' ? -$amount : $amount;
        }
        $metrics['net_paid'] += $net;
        if ($deal->signed_at && (float) $deal->amount > 0 && $net + 0.001 >= (float) $deal->amount) {
            $metrics['paid_orders']++;
        }
    }
}
