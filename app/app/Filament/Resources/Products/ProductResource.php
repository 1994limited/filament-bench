<?php

namespace App\Filament\Resources\Products;

use App\Bench\Variant;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * Editable columns (select, text input, toggle), money and markdown cells;
 * an edit form in tabs with a rich editor holding images, file uploads, a
 * code editor and a tags input.
 */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::where('stock', '<', 10)->count();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->tabs([
                Tab::make('Details')->schema([
                    Grid::make(2)->schema([
                        TextInput::make('name')->required(),
                        TextInput::make('sku')->required(),
                        Select::make('category_id')->relationship('category', 'name')->required(),
                        TextInput::make('price')->numeric()->required(),
                        TextInput::make('stock')->numeric()->required(),
                        Toggle::make('is_visible'),
                    ]),
                ]),
                Tab::make('Content')->schema([
                    RichEditor::make('description')->fileAttachmentsDisk('media')->fileAttachmentsDirectory('attachments'),
                ]),
                Tab::make('Media')->schema([
                    FileUpload::make('image')->disk('media')->directory('products')->image(),
                    FileUpload::make('images')->disk('media')->directory('products')->multiple()->image(),
                ]),
                Tab::make('Specs')->schema([
                    CodeEditor::make('specs')->language(Language::Json),
                ]),
                Tab::make('Tags')->schema([
                    SpatieTagsInput::make('tags'),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')->disk('media'),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('sku')->searchable(),
                SelectColumn::make('category_id')->label('Category')->options(Variant::on('static_options') ? Category::query()->orderBy('name')->pluck('name', 'id')->all() : fn () => Category::query()->orderBy('name')->pluck('name', 'id')->all()),
            TextColumn::make('price')->money('EUR', divideBy: 100)->sortable(),
                TextInputColumn::make('stock')->rules(['integer', 'min:0']),
                ToggleColumn::make('is_visible'),
            TextColumn::make('summary')->markdown()->limit(80),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('id')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
