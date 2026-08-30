<?php

declare(strict_types=1);

namespace Tests\Feature\Promo;

use App\Enums\Role;
use Illuminate\Support\Facades\DB;

/**
 * Административные отчёты и карта прав.
 */
class PromoReportTest extends PromoAccessTestBase
{
    /**
     * Карта прав — то, чем держится разделение «куратор не подтверждает».
     * Тест закрепляет её, чтобы способность не появилась у роли по недосмотру.
     */
    public function test_curator_has_no_confirmation_ability(): void
    {
        $this->assertTrue(Role::Curator->can('promo.curate'));
        $this->assertFalse(Role::Curator->can('promo.confirm'));
        $this->assertFalse(Role::Curator->can('promo.admin'));

        $this->assertTrue(Role::Superadmin->can('promo.confirm'));
        $this->assertFalse(Role::Partner->can('promo.confirm'));
        $this->assertFalse(Role::Client->can('promo.confirm'));

        // Единственный обладатель — суперадмин.
        $holders = array_filter(
            Role::cases(),
            static fn (Role $role): bool => $role->can('promo.confirm'),
        );

        $this->assertSame([Role::Superadmin], array_values($holders));
    }

    public function test_source_report_connects_a_closed_deal_to_the_advertising(): void
    {
        $this->attributedClient();
        $this->closedPromo();

        $row = $this->actingAs($this->admin, 'api')
            ->getJson('/admin/promo-codes/reports/sources')
            ->assertOk()
            ->json('rows.data.0');

        $this->assertSame('yandex-direct', $row['source']);
        $this->assertSame('kuhni-msk', $row['campaign']);
        $this->assertSame('111', $row['campaign_id']);
        $this->assertSame('222', $row['ad_group_id']);
        $this->assertSame('333', $row['ad_id']);
        $this->assertSame('444', $row['keyword_id']);
        $this->assertSame('7654321', $row['yclid']);
        $this->assertSame('Client', $row['client']);
        $this->assertSame('Curator', $row['curator']);
        $this->assertSame('Partner', $row['partner']);
        $this->assertSame(0, bccomp('100000.00', (string) $row['gross_amount'], 2));
        $this->assertSame(0, bccomp('10000.00', (string) $row['discount_amount'], 2));
        $this->assertSame(0, bccomp('90000.00', (string) $row['net_amount'], 2));

        // Интервалы воронки: от рекламы к регистрации, дальше по этапам.
        $this->assertIsInt($row['timings']['ad_to_registration']);
        $this->assertIsInt($row['timings']['registration_to_activation']);
        $this->assertIsInt($row['timings']['activation_to_deal']);
    }

    public function test_partners_report_aggregates_closed_deals(): void
    {
        $this->closedPromo();

        $row = $this->actingAs($this->admin, 'api')
            ->getJson('/admin/promo-codes/reports/partners')
            ->assertOk()
            ->json('partners.0');

        $this->assertSame($this->partner->id, (int) $row['partner_id']);
        $this->assertSame(1, (int) $row['deals']);
        $this->assertSame(0, bccomp('90000.00', (string) $row['net_total'], 2));
    }

    public function test_curators_report_lists_every_curator(): void
    {
        $this->closedPromo();
        $this->promoUser('curator2@example.test', Role::Curator);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/admin/promo-codes/reports/curators')
            ->assertOk();

        $this->assertCount(2, $response->json('curators'));
        $this->assertSame('unconfigured', $response->json('rule.rule'));
        $this->assertSame(1, $response->json('curators.0.closed_deals'));
    }

    public function test_registry_filters_by_source_status_and_participants(): void
    {
        $this->attributedClient();
        $this->closedPromo();

        $this->actingAs($this->admin, 'api')
            ->getJson('/admin/promo-codes?utm_source=yandex-direct')
            ->assertOk()
            ->assertJsonPath('promo_codes.total', 1);

        $this->actingAs($this->admin, 'api')
            ->getJson('/admin/promo-codes?utm_source=vk')
            ->assertOk()
            ->assertJsonPath('promo_codes.total', 0);

        $this->actingAs($this->admin, 'api')
            ->getJson('/admin/promo-codes?status=closed')
            ->assertOk()
            ->assertJsonPath('promo_codes.total', 1);

        $this->actingAs($this->admin, 'api')
            ->getJson("/admin/promo-codes?curator_id={$this->curator->id}")
            ->assertOk()
            ->assertJsonPath('promo_codes.total', 1);
    }

    /**
     * Списки не должны множить запросы: отчёт растёт вместе с реестром,
     * а количество запросов обязано оставаться постоянным.
     */
    public function test_listing_does_not_grow_queries_with_rows(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $client = $this->promoUser("client{$i}@example.test");
            $promo = $this->makePromo(['client' => $client]);
            $this->service()->activate($this->curator, $promo);
        }

        DB::enableQueryLog();
        $this->actingAs($this->admin, 'api')->getJson('/admin/promo-codes')->assertOk();
        $fiveRows = count(DB::getQueryLog());
        DB::flushQueryLog();

        for ($i = 5; $i < 15; $i++) {
            $client = $this->promoUser("client{$i}@example.test");
            $promo = $this->makePromo(['client' => $client]);
            $this->service()->activate($this->curator, $promo);
        }

        DB::flushQueryLog();
        $this->actingAs($this->admin, 'api')->getJson('/admin/promo-codes')->assertOk();
        $fifteenRows = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $fiveRows,
            $fifteenRows,
            'Количество запросов растёт вместе с числом строк — появился N+1.',
        );
    }

    public function test_curator_report_is_scoped_to_the_requesting_curator(): void
    {
        $this->closedPromo();

        $other = $this->promoUser('curator2@example.test', Role::Curator);

        $mine = $this->actingAs($this->curator, 'api')->getJson('/promo/curator/report')->assertOk();
        $theirs = $this->actingAs($other, 'api')->getJson('/promo/curator/report')->assertOk();

        $this->assertSame(1, $mine->json('report.closed_deals'));
        $this->assertSame(0, $theirs->json('report.closed_deals'));
    }

    public function test_curator_queue_shows_clients_without_promo_codes(): void
    {
        $waiting = $this->promoUser('waiting@example.test');
        $this->makePromo();

        $response = $this->actingAs($this->curator, 'api')
            ->getJson('/promo/curator/queue')
            ->assertOk();

        $emails = array_column($response->json('clients.data'), 'email');

        $this->assertContains($waiting->email, $emails);
        $this->assertNotContains($this->client->email, $emails);

        // Признак «из рекламы» есть, самих идентификаторов нет.
        $body = $response->getContent();
        $this->assertStringNotContainsString('yclid', $body);
        $this->assertStringNotContainsString('utm_', $body);
    }
}
