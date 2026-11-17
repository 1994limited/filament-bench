<?php

namespace App\Filament\Widgets;

use App\Models\Customer;
use App\Models\Order;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** A stats widget left on its default polling interval, with an aggregate query per stat and a sparkline. */
class ShopStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $perMonth = Order::query()
            ->selectRaw("strftime('%Y-%m', placed_at) as month, count(*) as n")
            ->groupBy('month')->orderBy('month')->limit(12)->pluck('n')->all();

        return [
            Stat::make('Customers', number_format(Customer::count())),
            Stat::make('Revenue', '$'.number_format(Order::sum('total') / 100)),
            Stat::make('Orders', number_format(Order::count()))->chart($perMonth),
        ];
    }
}
