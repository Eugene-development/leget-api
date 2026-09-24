<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\CrmDocumentService;
use App\Services\Crm\CrmStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class CrmDocumentController extends Controller
{
    public function __construct(private CrmAccess $access, private CrmStore $store, private CrmDocumentService $documents) {}

    public function fields(Request $r, string $site)
    {
        $this->access->site($r->user(), $site);

        return ['fields' => CrmDocumentService::FIELDS];
    }

    public function saveTemplate(Request $r, string $site, ?string $id = null)
    {
        $this->access->site($r->user(), $site, true);

        return $this->store->mutate($r, $site, function () use ($r, $site, $id) {
            $this->access->site($r->user()->fresh(), $site, true);
            $v = $r->validate(['name' => 'required|string|max:255', 'kind' => 'required|in:contract,act', 'content' => 'required|array', 'version' => ($id ? 'required' : 'sometimes').'|integer|min:1']);
            $this->documents->validate($v['content']);
            $data = array_intersect_key($v, array_flip(['name', 'kind', 'content']));
            $row = $id ? $this->store->update('crm_templates', $this->store->row('crm_templates', $site, $id, true), $v['version'], $data) : $this->store->insert('crm_templates', $site, $data);
            $this->store->event($r, $site, 'templates', $row->id, 'draft_saved', ['name' => $row->name]);

            return ['success' => true, 'item' => $this->store->present($row)];
        });
    }

    public function publish(Request $r, string $site, string $id)
    {
        $this->access->site($r->user(), $site, true);

        return $this->store->mutate($r, $site, function () use ($r, $site, $id) {
            $this->access->site($r->user()->fresh(), $site, true);
            $v = $r->validate(['version' => 'required|integer|min:1']);
            $template = $this->store->row('crm_templates', $site, $id, true);
            $this->documents->validate(json_decode($template->content, true));
            $number = (int) $template->published_version + 1;
            DB::table('crm_template_versions')->insert(['id' => (string) Str::ulid(), 'template_id' => $id, 'number' => $number, 'name' => $template->name, 'kind' => $template->kind, 'content' => $template->content, 'created_by' => $r->user()->id, 'created_at' => now()]);
            $saved = $this->store->update('crm_templates', $template, $v['version'], ['published_version' => $number]);
            $this->store->event($r, $site, 'templates', $id, 'published', ['number' => $number]);

            return ['success' => true, 'item' => $this->store->present($saved)];
        });
    }

    public function preview(Request $r, string $site, string $id)
    {
        $this->access->site($r->user(), $site);
        $v = $r->validate(['template_id' => 'required|string|size:26']);
        [$deal, $template, $version, $content, $values] = $this->source($site, $id, $v['template_id']);
        $html = $this->documents->html($content, $values);

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8')->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'")->header('Cache-Control', 'private, no-store');
    }

    public function generate(Request $r, string $site, string $id)
    {
        $this->access->site($r->user(), $site);
        $stored = null;
        try {
            return $this->store->mutate($r, $site, function () use ($r, $site, $id, &$stored) {
                $v = $r->validate(['template_id' => 'required|string|size:26', 'version' => 'required|integer|min:1']);
                [$deal, $template, $version, $content, $values] = $this->source($site, $id, $v['template_id']);
                abort_unless((int) $deal->version === (int) $v['version'], 409, 'Сделка изменилась. Обновите карточку перед созданием документа.');
                $html = $this->documents->html($content, $values);
                $stored = $this->documents->store($site, $id, $this->documents->pdf($html));
                $doc = $this->store->insert('crm_documents', $site, $stored + [
                    'deal_id' => $id, 'template_version_id' => $version->id, 'name' => $version->name.'.pdf', 'kind' => $version->kind,
                    'snapshot' => ['content' => $content, 'values' => $values, 'template_name' => $version->name, 'template_number' => $version->number],
                    'mime' => 'application/pdf', 'created_by' => $r->user()->id,
                ]);
                $this->store->event($r, $site, 'documents', $doc->id, 'generated', ['name' => $doc->name], $deal->client_id, $id);

                return ['success' => true, 'item' => $this->store->present($doc)];
            }, 1);
        } catch (\Throwable $e) {
            if ($stored) {
                Storage::disk($stored['disk'])->delete($stored['path']);
            }
            Log::warning('CRM document generation failed', ['site' => $site, 'error_type' => class_basename($e)]);
            throw $e;
        }
    }

    public function upload(Request $r, string $site, string $id)
    {
        $this->access->site($r->user(), $site);
        $r->validate(['file' => 'required|file|mimes:pdf,jpg,jpeg,png,docx|max:10240']);
        $stored = null;
        try {
            return $this->store->mutate($r, $site, function () use ($r, $site, $id, &$stored) {
                $deal = $this->store->row('crm_deals', $site, $id);
                $file = $r->file('file');
                $stored = $this->documents->store($site, $id, file_get_contents($file->getRealPath()));
                $doc = $this->store->insert('crm_documents', $site, $stored + ['deal_id' => $id, 'name' => mb_substr(basename($file->getClientOriginalName()), 0, 200), 'kind' => 'attachment', 'mime' => $file->getMimeType(), 'created_by' => $r->user()->id]);
                $this->store->event($r, $site, 'documents', $doc->id, 'uploaded', ['name' => $doc->name], $deal->client_id, $id);

                return ['success' => true, 'item' => $this->store->present($doc)];
            }, 1);
        } catch (\Throwable $e) {
            if ($stored) {
                Storage::disk($stored['disk'])->delete($stored['path']);
            }
            throw $e;
        }
    }

    public function download(Request $r, string $site, string $id)
    {
        $this->access->site($r->user(), $site);
        $doc = $this->store->row('crm_documents', $site, $id);
        $bytes = Crypt::decryptString(Storage::disk($doc->disk)->get($doc->path));
        abort_unless(hash_equals($doc->sha256, hash('sha256', $bytes)), 500, 'Файл повреждён.');

        return response()->streamDownload(fn () => print ($bytes), $doc->name, ['Content-Type' => $doc->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function source(string $site, string $dealId, string $templateId): array
    {
        $deal = $this->store->row('crm_deals', $site, $dealId);
        $template = $this->store->row('crm_templates', $site, $templateId);
        abort_unless($template->published_version, 422, 'Владелец ещё не опубликовал шаблон.');
        $version = DB::table('crm_template_versions')->where('template_id', $templateId)->where('number', $template->published_version)->first();
        abort_unless($version, 422, 'Опубликованная версия не найдена.');
        $client = $this->store->row('crm_clients', $site, $deal->client_id);
        $settings = $this->store->query('crm_settings', $site)->first();
        $values = $this->documents->values($deal, $client, json_decode($settings->requisites ?? '{}', true) ?? []);

        return [$deal, $template, $version, json_decode($version->content, true), $values];
    }
}
