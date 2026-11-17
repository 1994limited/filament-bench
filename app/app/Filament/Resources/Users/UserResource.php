<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** A small resource, so the sidebar has the number of badges a real panel has. */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?int $navigationSort = 8;

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name'),
            TextColumn::make('email'),
        ])->defaultSort('id');
    }

    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/')];
    }
}
