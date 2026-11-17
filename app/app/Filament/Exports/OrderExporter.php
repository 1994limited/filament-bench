<?php

namespace App\Filament\Exports;

use App\Models\Order;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class OrderExporter extends Exporter
{
    protected static ?string $model = Order::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('number'),
            ExportColumn::make('customer.name'),
            ExportColumn::make('status'),
            ExportColumn::make('total'),
            ExportColumn::make('items_count')->counts('items'),
            ExportColumn::make('placed_at'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return "Exported {$export->successful_rows} rows.";
    }
}
