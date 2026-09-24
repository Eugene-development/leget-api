<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class CrmBackfill extends Command
{
    protected $signature = 'crm:backfill {--apply : Apply verified historical links; default is report only}';

    protected $description = 'Report and optionally bind historical requests to sites, without sending mail';

    public function handle(): int
    {
        $matched = 0;
        $unassigned = 0;
        DB::table('service_requests')->whereNull('license_id')->orderBy('id')->chunkById(200, function ($rows) use (&$matched, &$unassigned) {
            foreach ($rows as $row) {
                $host = strtolower(preg_replace('/^www\./i', '', parse_url($row->source_url ?? '', PHP_URL_HOST) ?: ''));
                // Require independent historical recipient + domain agreement, and a pre-existing license.
                $sites = $host && $host === $row->site_domain && $row->recipient_email
                    ? DB::table('licenses')->join('users', 'users.id', '=', 'licenses.user_id')->where('licenses.domain', $host)
                        ->where('users.email', $row->recipient_email)->where('licenses.created_at', '<=', $row->created_at)->get(['licenses.id']) : collect();
                if ($sites->count() !== 1) {
                    $unassigned++;

                    continue;
                }
                $matched++;
                if ($this->option('apply')) {
                    DB::table('service_requests')->where('id', $row->id)->whereNull('license_id')->update(['license_id' => $sites[0]->id, 'version' => DB::raw('version + 1')]);
                }
            }
        });
        $this->table(['Mode', 'Verified links', 'Remain unassigned'], [[$this->option('apply') ? 'apply' : 'dry-run', $matched, $unassigned]]);

        return self::SUCCESS;
    }
}
