<?php

namespace App\Filament\Exports;

use App\Models\Product;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class ProductExporter extends Exporter
{
    protected static ?string $model = Product::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('sku'),
            ExportColumn::make('name'),
            ExportColumn::make('category.name'),
            ExportColumn::make('price'),
            ExportColumn::make('stock'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return "Exported {$export->successful_rows} rows.";
    }
}
