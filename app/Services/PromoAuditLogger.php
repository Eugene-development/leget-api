<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PromoAction;
use App\Enums\PromoCodeStatus;
use App\Enums\Role;
use App\Models\PromoCodeEvent;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Запись в журнал аудита.
 *
 * Пишется в той же транзакции, что и само действие: разделённые, они разойдутся
 * ровно в тот момент, когда журнал нужнее всего — при откате половины операции.
 * Поэтому логгер не бросает исключений «мягко» и не глотает ошибки: не смогли
 * записать событие — не должно состояться и действие.
 *
 * IP и user agent берутся из текущего запроса, если он есть. В консольной
 * команде запроса нет, и колонки остаются пустыми — выдумывать «127.0.0.1»
 * значит наполнять журнал ложью.
 */
final class PromoAuditLogger
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>|null  $changes  Безопасный diff: только те поля,
     *                                              которые вызывающий перечислил явно.
     *                                              Секретов и лишних персональных
     *                                              данных здесь быть не должно.
     */
    public function log(
        PromoAction $action,
        string $subjectType,
        string $subjectId,
        ?User $actor = null,
        ?string $promoCodeId = null,
        ?PromoCodeStatus $from = null,
        ?PromoCodeStatus $to = null,
        ?array $changes = null,
        ?string $reason = null,
    ): PromoCodeEvent {
        $event = new PromoCodeEvent;

        $event->forceFill([
            'actor_id' => $actor?->id,
            'actor_role' => ($actor?->role ?? Role::Client)->value,
            'action' => $action->value,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'promo_code_id' => $promoCodeId,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'changes' => $changes,
            'reason' => $reason,
            'ip_address' => $this->ip(),
            'user_agent' => $this->userAgent(),
            'created_at' => now(),
        ])->save();

        return $event;
    }

    private function ip(): ?string
    {
        // Запросы к промо-маршрутам приходят от серверной части leget-main,
        // поэтому реальный адрес посетителя лежит в X-Forwarded-For — так же,
        // как это уже делает вход клиента. Значение заголовка подделываемо
        // и границей доступа не является: это след для разбора, не проверка.
        $forwarded = trim(explode(',', (string) $this->request->header('X-Forwarded-For'))[0]);

        if ($forwarded !== '') {
            return substr($forwarded, 0, 45);
        }

        $ip = $this->request->ip();

        return $ip === null ? null : substr($ip, 0, 45);
    }

    private function userAgent(): ?string
    {
        $agent = $this->request->userAgent();

        return $agent === null ? null : substr($agent, 0, 255);
    }
}
