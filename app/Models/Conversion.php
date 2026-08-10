<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Conversion extends Model
{
    use HasUlids;

    public const CHANNEL_ONLINE = 'online';

    public const CHANNEL_OFFLINE = 'offline';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'channel',
        'type',
        'name',
        'contact',
        'ad_id',
        'comment',
        'source_url',
        'service_request_id',
    ];
}
