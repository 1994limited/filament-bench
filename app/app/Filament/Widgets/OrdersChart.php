<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Widgets\ChartWidget;

/** A chart widget left on its default polling interval: one aggregate query per poll. */
class OrdersChart extends ChartWidget
{
    protected ?string $heading = 'Orders per month';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $rows = Order::query()
            ->selectRaw("strftime('%Y-%m', placed_at) as month, count(*) as n, sum(total) as revenue")
            ->groupBy('month')->orderBy('month')->get();

        return [
            'datasets' => [
                ['label' => 'Orders', 'data' => $rows->pluck('n')->all()],
            ],
            'labels' => $rows->pluck('month')->all(),
        ];
    }
}
