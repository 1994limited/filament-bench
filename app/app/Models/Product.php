<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Tags\HasTags;

class Product extends Model
{
    use HasTags;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_visible' => 'boolean', 'images' => 'array'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
