<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Таблица счетов на оплату через расчётный счёт.
     *
     * Счёт создаётся пользователем, скачивается как HTML/PDF и оплачивается
     * по реквизитам. Зачисление баланса производится вручную после подтверждения.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('wallet_id')->constrained()->onDelete('cascade');

            // Уникальный номер счёта, формат: INV-YYYYMM-XXXXX
            $table->string('number', 30)->unique();

            // Сумма к оплате
            $table->decimal('amount', 15, 2);

            // Статус: pending — выставлен, paid — оплачен, cancelled — отменён
            $table->enum('status', ['pending', 'paid', 'cancelled'])->default('pending');

            // Данные плательщика
            $table->string('company_name');
            $table->string('inn', 20)->nullable();

            // Когда зачислен (при ручном подтверждении)
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
