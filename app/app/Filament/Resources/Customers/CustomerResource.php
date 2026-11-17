<?php

namespace App\Filament\Resources\Customers;

use App\Bench\Variant;
use App\Enums\CustomerStatus;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Customer;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * A list page shaped like the report's "representative" table: 50 rows with
 * an image column on a private disk, relationship search, a BelongsTo filter,
 * money and date columns, and bulk actions with a per-record selectable check.
 */
class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Customer')->columns(2)->schema([
                TextInput::make('name')->required(),
                TextInput::make('email')->email()->required(),
                Select::make('country_id')->relationship('country', 'name'),
                Select::make('status')->options(CustomerStatus::class)->required(),
                Toggle::make('is_active'),
                FileUpload::make('avatar')->disk('media')->directory('avatars')->image(),
            ]),
            MarkdownEditor::make('notes')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('avatar')->disk('media')->circular()->checkFileExistence(! Variant::on('no_exists_check')),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('email')->searchable(),
            TextColumn::make('country.name')->searchable()->sortable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('balance')->money('USD', divideBy: 100)->sortable(),
                IconColumn::make('is_active')->boolean(),
            TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('country')->relationship('country', 'name'),
                SelectFilter::make('status')->options(CustomerStatus::class),
            ])
            ->recordActions([
                ActionGroup::make([EditAction::make(), DeleteAction::make()]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->checkIfRecordIsSelectableUsing(Variant::on('no_selectable_check') ? null : fn (Customer $record): bool => $record->is_active)
            ->defaultSort('id')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
