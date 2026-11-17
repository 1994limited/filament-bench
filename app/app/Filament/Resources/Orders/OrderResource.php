<?php

namespace App\Filament\Resources\Orders;

use App\Bench\Variant;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\QueryBuilder\Constraints\DateConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\NumberConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

/**
 * An edit page with a searchable BelongsTo Select, a live field and a
 * relationship Repeater whose items each hold a (non-searchable) relationship
 * Select that disables options picked in sibling items; a view page with a
 * RepeatableEntry over a relationship; a list with a query builder, a
 * summary and groups.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?int $navigationSort = 3;

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::where('status', OrderStatus::New)->count();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Order')->columnSpanFull()->schema([
                Grid::make(3)->schema([
                    TextInput::make('number')->required(),
                    Select::make('customer_id')->relationship('customer', 'name')->searchable()->required(),
                    Select::make('status')->options(OrderStatus::class)->required()->live(),
                    DateTimePicker::make('placed_at')->required(),
                    DateTimePicker::make('shipped_at')->visible(fn (Get $get): bool => in_array($get('status'), [OrderStatus::Shipped, OrderStatus::Delivered, 'shipped', 'delivered'], true)),
                    TextInput::make('total')->numeric()->required(),
                ]),
                Textarea::make('notes'),
            ]),
            Section::make('Items')->columnSpanFull()->schema([
                Repeater::make('items')->relationship()->orderColumn('sort')->columns(3)->schema([
                    Select::make('product_id')->relationship('product', 'name')->required()->disableOptionsWhenSelectedInSiblingRepeaterItems(! Variant::on('no_sibling_disable')),
                    TextInput::make('qty')->numeric()->required()->live(onBlur: true),
                    TextInput::make('unit_price')->numeric()->required(),
                ]),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Order')->columns(3)->columnSpanFull()->schema([
                TextEntry::make('number'),
                TextEntry::make('customer.name'),
                TextEntry::make('status')->badge(),
                TextEntry::make('total')->money('USD', divideBy: 100),
                TextEntry::make('placed_at')->dateTime(),
                TextEntry::make('shipped_at')->dateTime()->placeholder('Not shipped'),
            ]),
            RepeatableEntry::make('items')->columns(3)->columnSpanFull()->schema([
                TextEntry::make('product.name'),
                TextEntry::make('qty'),
                TextEntry::make('unit_price')->money('USD', divideBy: 100),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
            TextColumn::make('number')->searchable(),
            TextColumn::make('customer.name')->searchable()->sortable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('total')->money('USD', divideBy: 100)->sortable()->summarize(Sum::make()->money('USD', divideBy: 100)),
            TextColumn::make('items_count')->counts('items')->label('Items'),
            TextColumn::make('placed_at')->dateTime()->sortable(),
            ])
            ->filters([
                QueryBuilder::make()->constraints([
                    TextConstraint::make('number'),
                    NumberConstraint::make('total'),
                    DateConstraint::make('placed_at'),
                ]),
            ])
            ->groups([Group::make('status')])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->defaultSort('placed_at', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
            'edit' => EditOrder::route('/{record}/edit'),
        ];
    }
}
