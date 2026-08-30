<?php

declare(strict_types=1);

namespace Tests\Feature\Promo;

use App\Enums\PromoCodeStatus;
use App\Enums\PromoDiscountType;
use App\Exceptions\PromoCodeException;
use App\Models\PromoCode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Создание промокода и валидация условий.
 */
class PromoCreationTest extends PromoTestCase
{
    public function test_percent_promo_code_is_created_with_unique_public_code(): void
    {
        $promo = $this->makePromo();

        $this->assertSame(PromoCodeStatus::Created, $promo->status);
        $this->assertSame(PromoDiscountType::Percent, $promo->discount_type);
        $this->assertSame('10.00', $promo->discount_value);
        // Валюта у процентной скидки бессмысленна и не хранится.
        $this->assertNull($promo->currency);
        $this->assertNotEmpty($promo->code);
        $this->assertSame($this->client->id, $promo->client_id);
        $this->assertSame($this->curator->id, $promo->curator_id);
    }

    public function test_fixed_promo_code_keeps_currency(): void
    {
        $promo = $this->makePromo([
            'discount_type' => 'fixed',
            'discount_value' => '5000',
            'currency' => 'RUB',
        ]);

        $this->assertSame(PromoDiscountType::Fixed, $promo->discount_type);
        $this->assertSame('5000.00', $promo->discount_value);
        $this->assertSame('RUB', $promo->currency);
    }

    public function test_percent_discount_above_hundred_is_rejected(): void
    {
        $this->expectException(PromoCodeException::class);
        $this->expectExceptionMessage('не может превышать 100');

        $this->makePromo(['discount_value' => '101']);
    }

    public function test_zero_discount_is_rejected_for_both_types(): void
    {
        foreach (['percent', 'fixed'] as $type) {
            try {
                $this->makePromo(['discount_type' => $type, 'discount_value' => '0']);
                $this->fail("Скидка 0 принята для типа {$type}.");
            } catch (PromoCodeException $e) {
                $this->assertSame('PROMO_DISCOUNT_INVALID', $e->errorCode());
            }
        }
    }

    public function test_negative_discount_is_rejected(): void
    {
        $this->expectException(PromoCodeException::class);

        $this->makePromo(['discount_type' => 'fixed', 'discount_value' => '-100']);
    }

    public function test_expiry_before_start_is_rejected(): void
    {
        $this->expectException(PromoCodeException::class);

        $this->makePromo([
            'starts_at' => now()->addDays(5)->toIso8601String(),
            'expires_at' => now()->addDay()->toIso8601String(),
        ]);
    }

    /**
     * Код называют вслух и печатают в договоре: восстановимой связи с клиентом
     * или рекламой в нём быть не должно.
     */
    public function test_public_code_contains_no_client_or_ad_identifiers(): void
    {
        $promo = $this->makePromo();

        $this->assertStringNotContainsString((string) $this->client->id, substr($promo->code, 3));
        $this->assertStringNotContainsString((string) $promo->getKey(), $promo->code);
    }

    /** Уникальность держит индекс БД, а не проверка в PHP. */
    public function test_database_refuses_a_duplicate_code(): void
    {
        $first = $this->makePromo();
        $second = $this->makePromo();

        $this->expectException(QueryException::class);

        PromoCode::query()->whereKey($second->getKey())->update(['code' => $first->code]);
    }

    /**
     * Промокод не должен указывать на несуществующую категорию: клиент увидел
     * бы скидку на то, чего нет.
     */
    public function test_subject_id_must_exist_for_referencing_types(): void
    {
        Schema::create('categories', function ($table): void {
            $table->char('id', 26)->primary();
            $table->string('value');
        });

        DB::table('categories')->insert([
            'id' => '01JQZZZZZZZZZZZZZZZZZZZZZZ',
            'value' => 'Кухни',
        ]);

        $promo = $this->makePromo([
            'subject_type' => 'category',
            'subject_id' => '01JQZZZZZZZZZZZZZZZZZZZZZZ',
        ]);

        $this->assertSame('01JQZZZZZZZZZZZZZZZZZZZZZZ', $promo->subject_id);

        try {
            $this->makePromo(['subject_type' => 'category', 'subject_id' => '01AAAAAAAAAAAAAAAAAAAAAAAA']);
            $this->fail('Промокод указал на несуществующую категорию.');
        } catch (PromoCodeException $e) {
            $this->assertSame('PROMO_SUBJECT_NOT_FOUND', $e->errorCode());
        }
    }

    /** Разовое предложение ссылки не имеет — проверять нечего. */
    public function test_custom_subject_needs_no_reference(): void
    {
        $promo = $this->makePromo(['subject_type' => 'custom', 'subject_title' => 'Скидка на замер']);

        $this->assertNull($promo->subject_id);
        $this->assertSame('Скидка на замер', $promo->subject_title);
    }

    /** Суммы хранятся строкой фиксированной точности, а не float. */
    public function test_money_is_never_a_float(): void
    {
        $promo = $this->makePromo([
            'discount_type' => 'fixed',
            'discount_value' => '1234.56',
            'minimum_order_amount' => '10000',
        ]);

        $this->assertIsString($promo->discount_value);
        $this->assertIsString($promo->minimum_order_amount);
        $this->assertSame('1234.56', $promo->discount_value);
        $this->assertSame('10000.00', $promo->minimum_order_amount);
    }
}
