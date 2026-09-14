<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

#[Fillable(['tag_group_id', 'name', 'normalized_name'])]
class Tag extends Model
{
    use HasUlids;

    public function group(): BelongsTo
    {
        return $this->belongsTo(TagGroup::class, 'tag_group_id');
    }

    public function mebelProjects(): MorphToMany
    {
        return $this->morphedByMany(MebelProject::class, 'taggable')->withTimestamps();
    }
}
