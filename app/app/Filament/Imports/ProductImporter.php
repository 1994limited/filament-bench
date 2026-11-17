<?php

namespace App\Filament\Imports;

use App\Models\Product;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

/** Products by SKU, with the category looked up by name: few distinct values over many rows. */
class ProductImporter extends Importer
{
    protected static ?string $model = Product::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('sku')->requiredMapping()->rules(['required', 'max:64']),
            ImportColumn::make('name')->requiredMapping()->rules(['required', 'max:255']),
            ImportColumn::make('category')->relationship(resolveUsing: 'name')->requiredMapping(),
            ImportColumn::make('price')->numeric()->rules(['required', 'integer']),
            ImportColumn::make('stock')->numeric()->rules(['required', 'integer']),
            ImportColumn::make('is_visible')->boolean()->rules(['boolean']),
        ];
    }

    public function resolveRecord(): Product
    {
        return Product::firstOrNew(['sku' => $this->data['sku']]);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        return "Imported {$import->successful_rows} products.";
    }
}
