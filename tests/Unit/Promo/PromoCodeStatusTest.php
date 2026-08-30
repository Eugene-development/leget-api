<?php

declare(strict_types=1);

namespace Tests\Unit\Promo;

use App\Enums\PromoCodeStatus;
use PHPUnit\Framework\TestCase;

/**
 * Таблица переходов — граница, на которой держится вся модель безопасности.
 * Проверяем не «переходы описаны», а что запрещённое действительно запрещено.
 */
class PromoCodeStatusTest extends TestCase
{
    public function test_created_cannot_jump_straight_to_closed(): void
    {
        $this->assertFalse(PromoCodeStatus::Created->canTransitionTo(PromoCodeStatus::Closed));
        $this->assertFalse(PromoCodeStatus::Created->canTransitionTo(PromoCodeStatus::DealReported));
        $this->assertFalse(PromoCodeStatus::Created->canTransitionTo(PromoCodeStatus::ClientConfirmed));
    }

    /**
     * Между «сделка заявлена» и «сделка закрыта» обязана стоять вторая сторона.
     * Прямой переход означал бы, что заявивший её же и закрыл.
     */
    public function test_reported_deal_cannot_be_closed_without_confirmation(): void
    {
        $this->assertFalse(PromoCodeStatus::DealReported->canTransitionTo(PromoCodeStatus::Closed));
        $this->assertTrue(PromoCodeStatus::DealReported->canTransitionTo(PromoCodeStatus::ClientConfirmed));
        $this->assertTrue(PromoCodeStatus::DealReported->canTransitionTo(PromoCodeStatus::Disputed));
        $this->assertTrue(PromoCodeStatus::ClientConfirmed->canTransitionTo(PromoCodeStatus::Closed));
    }

    public function test_expired_code_is_terminal_and_not_redeemable(): void
    {
        $this->assertTrue(PromoCodeStatus::Expired->isTerminal());
        $this->assertFalse(PromoCodeStatus::Expired->isRedeemable());
        $this->assertSame([], PromoCodeStatus::Expired->allowedTransitions());
    }

    public function test_cancelled_code_is_terminal(): void
    {
        $this->assertTrue(PromoCodeStatus::Cancelled->isTerminal());
        $this->assertFalse(PromoCodeStatus::Cancelled->isRedeemable());
    }

    /**
     * Возврат допустим только после закрытия и сам является тупиком:
     * из него нельзя вернуться в закрытую сделку и снова начислить.
     */
    public function test_refund_follows_closing_and_ends_the_line(): void
    {
        $this->assertTrue(PromoCodeStatus::Closed->canTransitionTo(PromoCodeStatus::Refunded));
        $this->assertTrue(PromoCodeStatus::Refunded->isTerminal());
        $this->assertFalse(PromoCodeStatus::Refunded->countsTowardsCommission());
        $this->assertTrue(PromoCodeStatus::Closed->countsTowardsCommission());
    }

    public function test_created_code_is_invisible_to_partner(): void
    {
        $this->assertFalse(PromoCodeStatus::Created->isVisibleToPartner());
        $this->assertTrue(PromoCodeStatus::Activated->isVisibleToPartner());
    }

    public function test_closed_code_cannot_be_redeemed_again(): void
    {
        $this->assertFalse(PromoCodeStatus::Closed->isRedeemable());
        $this->assertFalse(PromoCodeStatus::Closed->canTransitionTo(PromoCodeStatus::DealReported));
    }
}
