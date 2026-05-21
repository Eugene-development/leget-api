<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ServiceRequest extends Model
{
    use HasUlids;

    /**
     * Статусы заявки
     */
    public const STATUS_NEW = 'new';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Типы услуг
     */
    public const TYPE_CONSULTATION = 'consultation';
    public const TYPE_DESIGN_PROJECT = 'design-project';
    public const TYPE_FURNITURE_PROJECT = 'furniture-project';
    public const TYPE_ASSEMBLY = 'assembly';
    public const TYPE_MEASUREMENT = 'measurement';
    public const TYPE_PARTNERSHIP = 'partnership';

    /**
     * Тип первичного ключа — строка (ULID).
     */
    protected $keyType = 'string';

    /**
     * Первичный ключ не является автоинкрементным.
     */
    public $incrementing = false;

    /**
     * Атрибуты, для которых разрешено массовое заполнение.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'service_type',
        'name',
        'phone',
        'message',
        'status',
        'ip_address',
        'user_agent',
        'source_url',
        'city',
    ];

    /**
     * Значения по умолчанию для атрибутов.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_NEW,
    ];
}
