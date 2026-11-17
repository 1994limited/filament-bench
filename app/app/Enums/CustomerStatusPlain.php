<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** CustomerStatus without HasIcon, for the `plain_enum` variant. */
enum CustomerStatusPlain: string implements HasColor, HasLabel
{
    case Lead = 'lead';
    case Active = 'active';
    case Churned = 'churned';
    case Vip = 'vip';

    public function getLabel(): string
    {
        return CustomerStatus::from($this->value)->getLabel();
    }

    public function getColor(): string
    {
        return CustomerStatus::from($this->value)->getColor();
    }
}
