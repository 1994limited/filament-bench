<?php

namespace App\Models;

use App\Bench\Variant;
use App\Enums\CustomerStatus;
use App\Enums\CustomerStatusPlain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => Variant::on('plain_enum') ? CustomerStatusPlain::class : CustomerStatus::class, 'is_active' => 'boolean'];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
