<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Схема промокодов для тестов.
 *
 * Миграции живут в отдельном сервисе leget-db, поэтому RefreshDatabase создаёт
 * пустую sqlite-базу и таблицы приходится описывать здесь — так же, как это
 * делают AdminConversionTest и соседи. Определения повторяют canonical-миграции
 * `2026_08_30_1000*`; при изменении схемы синхронизировать.
 */
trait CreatesPromoSchema
{
    protected function createPromoSchema(): void
    {
        if (! Schema::hasTable('conversions')) {
            Schema::create('conversions', function (Blueprint $table): void {
                $table->char('id', 26)->primary();
                $table->string('channel', 16);
                $table->string('type', 64);
                $table->string('name');
                $table->string('contact', 255)->nullable();
                $table->string('ad_id', 32)->nullable();
                $table->text('comment')->nullable();
                $table->string('source_url', 500)->nullable();
                $table->char('service_request_id', 26)->nullable()->unique();
                $table->char('promo_code_deal_id', 26)->nullable()->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ad_attributions')) {
            Schema::create('ad_attributions', function (Blueprint $table): void {
                $table->char('id', 26)->primary();
                $table->unsignedBigInteger('user_id')->unique();
                $table->string('visitor_id', 64)->nullable();

                foreach (['first', 'last'] as $prefix) {
                    $table->string($prefix.'_yclid', 64)->nullable();
                    $table->string($prefix.'_utm_source', 255)->nullable();
                    $table->string($prefix.'_utm_medium', 255)->nullable();
                    $table->string($prefix.'_utm_campaign', 255)->nullable();
                    $table->string($prefix.'_utm_content', 255)->nullable();
                    $table->string($prefix.'_utm_term', 255)->nullable();
                    $table->string($prefix.'_campaign_id', 64)->nullable();
                    $table->string($prefix.'_ad_group_id', 64)->nullable();
                    $table->string($prefix.'_ad_id', 64)->nullable();
                    $table->string($prefix.'_keyword_id', 64)->nullable();
                    $table->text($prefix.'_landing_url')->nullable();
                    $table->text($prefix.'_referrer')->nullable();
                    $table->timestamp($prefix.'_touched_at')->nullable();
                }

                $table->timestamps();
            });
        }

        if (! Schema::hasTable('promo_codes')) {
            Schema::create('promo_codes', function (Blueprint $table): void {
                $table->char('id', 26)->primary();
                $table->string('code', 32)->unique();
                $table->unsignedBigInteger('client_id');
                $table->unsignedBigInteger('curator_id')->nullable();
                $table->unsignedBigInteger('partner_id')->nullable();
                $table->char('attribution_id', 26)->nullable();
                $table->string('subject_type', 32);
                $table->string('subject_id', 26)->nullable();
                $table->string('subject_title', 255);
                $table->string('discount_type', 16);
                $table->decimal('discount_value', 15, 2);
                $table->char('currency', 3)->nullable();
                $table->decimal('minimum_order_amount', 15, 2)->nullable();
                $table->text('terms')->nullable();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->string('status', 24)->default('created');
                $table->unsignedBigInteger('created_by');
                $table->timestamp('activated_at')->nullable();
                $table->unsignedBigInteger('activated_by')->nullable();
                $table->timestamp('presented_at')->nullable();
                $table->timestamp('order_created_at')->nullable();
                $table->timestamp('deal_reported_at')->nullable();
                $table->unsignedBigInteger('deal_reported_by')->nullable();
                $table->timestamp('client_confirmed_at')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->unsignedBigInteger('confirmed_by')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->unsignedBigInteger('closed_by')->nullable();
                $table->timestamp('disputed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamp('refunded_at')->nullable();
                $table->timestamp('expired_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('promo_code_deals')) {
            Schema::create('promo_code_deals', function (Blueprint $table): void {
                $table->char('id', 26)->primary();
                // Уникальность — то самое, что не даёт погасить код дважды.
                $table->char('promo_code_id', 26)->unique();
                $table->string('order_number', 64);
                $table->date('deal_date');
                $table->decimal('gross_amount', 15, 2);
                $table->decimal('discount_amount', 15, 2);
                $table->decimal('net_amount', 15, 2);
                $table->char('currency', 3)->default('RUB');
                $table->string('category', 120)->nullable();
                $table->text('comment')->nullable();
                $table->string('document_url', 500)->nullable();
                $table->unsignedBigInteger('reported_by');
                $table->string('reported_by_role', 32);
                $table->boolean('on_behalf_of_partner')->default(false);
                $table->text('behalf_reason')->nullable();
                $table->string('client_response', 32)->nullable();
                $table->timestamp('client_responded_at')->nullable();
                $table->text('client_comment')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->unsignedBigInteger('confirmed_by')->nullable();
                $table->text('confirmation_reason')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->unsignedBigInteger('closed_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('promo_code_events')) {
            Schema::create('promo_code_events', function (Blueprint $table): void {
                $table->char('id', 26)->primary();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('actor_role', 32)->nullable();
                $table->string('action', 48);
                $table->string('subject_type', 48);
                $table->string('subject_id', 26);
                $table->string('promo_code_id', 26)->nullable();
                $table->string('from_status', 24)->nullable();
                $table->string('to_status', 24)->nullable();
                $table->json('changes')->nullable();
                $table->text('reason')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('curator_commissions')) {
            Schema::create('curator_commissions', function (Blueprint $table): void {
                $table->char('id', 26)->primary();
                $table->char('promo_code_deal_id', 26);
                $table->string('promo_code_id', 26);
                $table->unsignedBigInteger('curator_id');
                $table->string('entry_type', 16);
                $table->string('rule', 48);
                $table->json('rule_snapshot')->nullable();
                $table->decimal('amount', 15, 2)->nullable();
                $table->char('currency', 3)->default('RUB');
                $table->decimal('deal_gross_amount', 15, 2);
                $table->decimal('deal_discount_amount', 15, 2);
                $table->timestamp('accrued_at');
                $table->text('reason')->nullable();
                $table->timestamps();

                $table->unique(['promo_code_deal_id', 'entry_type']);
            });
        }

        if (! Schema::hasTable('user_notifications')) {
            Schema::create('user_notifications', function (Blueprint $table): void {
                $table->char('id', 26)->primary();
                $table->unsignedBigInteger('user_id');
                $table->string('type', 48);
                $table->string('title', 255);
                $table->text('body');
                $table->json('payload')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function promoUser(string $email, Role $role = Role::Client, ?string $phone = null): User
    {
        $user = User::query()->create([
            'name' => ucfirst(strstr($email, '@', true) ?: $email),
            'email' => $email,
            'password' => Hash::make('secret-secret'),
        ]);

        $user->forceFill(['role' => $role->value, 'phone' => $phone])->save();

        return $user->fresh();
    }
}
