<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\License;
use App\Services\PagePublicationService;
use App\Services\SitePromotions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class BackfillSitePromotions extends Command
{
    protected $signature = 'site-promotions:backfill';

    protected $description = 'Сохранить текущие акции шаблонов в данных каждого сайта';

    public function handle(SitePromotions $promotions, PagePublicationService $publication): int
    {
        $count = 0;
        $pending = 0;
        License::query()->select('id')->lazyById()->each(function (License $item) use ($promotions, $publication, &$count, &$pending) {
            DB::transaction(function () use ($item, $promotions, $publication, &$count, &$pending) {
                $license = License::whereKey($item->id)->lockForUpdate()->first();
                if ($license && $promotions->supports($license, '/actions')) {
                    $drafts = DB::table('page_drafts')->where('license_id', $item->id)->pluck('slug');
                    if ($drafts->contains(fn (string $slug) => $promotions->supports($license, $slug))) {
                        $pending++;

                        return;
                    }
                    $promotions->materialize($license);
                    DB::afterCommit(fn () => $publication->invalidate($license->id));
                    $count++;
                }
            }, 3);
        });
        $this->info("Обработано сайтов: {$count}");
        if ($pending) {
            $this->warn("Сайтов с черновиками страниц акций: {$pending}. Повторите после публикации или отмены черновиков.");
        }

        return self::SUCCESS;
    }
}
