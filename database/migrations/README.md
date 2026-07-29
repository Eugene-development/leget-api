# Здесь не должно быть миграций

Схема базы данных проекта — **только в leget-db** (`ms/leget-db/database/migrations/`).
Это единственный источник истины: там лежат и структура таблиц, и data-миграции.

Причина, по которой каталог оставлен пустым, а не удалён: `php artisan make:migration`
создаёт файл именно здесь, и без напоминания копия схемы легко появляется снова.
Такие дубли уже случались (`users`, `cache`, `jobs`, `page_components`, `invoices`) и
успели разойтись с canonical-версией.

## Что делать вместо этого

Новая таблица или изменение схемы:

```bash
cd ms/leget-db
php artisan make:migration create_something_table
php artisan migrate
```

Миграции пишутся без моделей leget-api (`App\Models\...`) — в leget-db их нет.
Для data-миграций используйте `DB::table(...)`.

## Схема для тестов leget-api

Тесты работают с sqlite `:memory:` и не видят миграции leget-db. Таблицы, нужные
большинству тестов (`users`, `page_components`), создаёт `Tests\TestCase::setUp()`.
Специфичные таблицы тесты создают сами через `Schema::create` — см., например,
`tests/Feature/Payment/OnlineTopUpTest.php`.

При изменении схемы в leget-db не забудьте синхронизировать тестовые определения.
