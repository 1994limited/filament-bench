<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Implements HasIcon but has no icons: the report says TextColumn leaves its
 * fast path for any HasIcon enum, whether or not it returns an icon.
 */
enum CustomerStatus: string implements HasColor, HasIcon, HasLabel
{
    case Lead = 'lead';
    case Active = 'active';
    case Churned = 'churned';
    case Vip = 'vip';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Lead => 'gray',
            self::Active => 'success',
            self::Churned => 'danger',
            self::Vip => 'warning',
        };
    }

    public function getIcon(): ?string
    {
        return null;
    }
}
