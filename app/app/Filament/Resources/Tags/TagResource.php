<?php

namespace App\Filament\Resources\Tags;

use App\Filament\Resources\Tags\Pages\ListTags;
use Spatie\Tags\Tag;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** A small resource, so the sidebar has the number of badges a real panel has. */
class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static ?int $navigationSort = 7;

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name'),
            TextColumn::make('type'),
        ])->defaultSort('id');
    }

    public static function getPages(): array
    {
        return ['index' => ListTags::route('/')];
    }
}
