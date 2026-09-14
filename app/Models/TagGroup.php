<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TagGroup extends Model
{
    public $timestamps = false;

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class)->orderBy('name')->orderBy('id');
    }
}
