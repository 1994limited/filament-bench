<?php

namespace App\Filament\Resources\OrderItems;

use App\Filament\Resources\OrderItems\Pages\ListOrderItems;
use App\Models\OrderItem;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** A small resource, so the sidebar has the number of badges a real panel has. */
class OrderItemResource extends Resource
{
    protected static ?string $model = OrderItem::class;

    protected static ?int $navigationSort = 6;

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('order_id'),
            TextColumn::make('product_id'),
            TextColumn::make('qty'),
        ])->defaultSort('id');
    }

    public static function getPages(): array
    {
        return ['index' => ListOrderItems::route('/')];
    }
}
