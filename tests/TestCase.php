<?php

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    /**
     * Базовая схема для тестов.
     *
     * Миграции проекта живут в отдельном сервисе leget-db, поэтому в leget-api
     * их нет и RefreshDatabase создаёт пустую sqlite-базу. Таблицы, на которые
     * опирается большинство тестов, создаются здесь; всё специфичное тесты
     * доопределяют сами в своём setUp().
     *
     * Определения повторяют canonical-миграции из
     * ms/leget-db/database/migrations — при изменении схемы синхронизировать.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSharedTestSchema();
    }

    protected function createSharedTestSchema(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->string('phone')->nullable();
                $table->rememberToken();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('page_components')) {
            Schema::create('page_components', function (Blueprint $table) {
                $table->char('id', 26)->primary();

                // pages / licenses создают сами тесты — в sqlite внешний ключ
                // разрешается по имени в момент запроса, а не при CREATE TABLE,
                // поэтому порядок создания таблиц здесь не важен. Ключи нужны
                // для проверки каскадного удаления компонентов вместе со страницей.
                $table->unsignedBigInteger('page_id');
                $table->foreign('page_id')->references('id')->on('pages')->cascadeOnDelete();

                $table->char('license_id', 26);
                $table->foreign('license_id')->references('id')->on('licenses')->cascadeOnDelete();

                $table->string('type');
                $table->json('data');
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['page_id', 'type']);
            });
        }
    }
}
