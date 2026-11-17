<?php

namespace App\Filament\Pages;

use App\Bench\Variant;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * A large settings-style form: 200 fields in 4 tabs of 5 sections, each
 * field's visibility depending on one live Select at the top (so every
 * field calls $get), and every fifth field hidden. This is where the
 * report's schema findings (closure evaluation, child schemas rebuilt,
 * live updates walking the tree, $get lookups) add up.
 */
class BigForm extends Page
{
    protected static ?string $navigationLabel = 'Big form';

    protected static ?int $navigationSort = 20;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['mode' => 'all']);
    }

    public function form(Schema $schema): Schema
    {
        $tabs = [];
        for ($t = 1; $t <= 4; $t++) {
            $sections = [];
            for ($s = 1; $s <= 5; $s++) {
                $fields = [];
                for ($f = 1; $f <= 10; $f++) {
                    $n = (($t - 1) * 5 + ($s - 1)) * 10 + $f;
                    $fields[] = TextInput::make("field_$n")
                        ->label("Field $n")
                        ->maxLength(100)
                        // the first and last fields are live too, so a live update can be measured from either end of the tree
                        ->live(onBlur: true, condition: $n === 1 || $n === 200)
                        ->visible(Variant::on('no_visible_closures') ? $n % 5 !== 0 : fn (Get $get): bool => $get('mode') !== 'none' && ($n % 5 !== 0 || $get('mode') === 'all'));
                }
                $sections[] = Section::make("Section $t.$s")->columns(2)->schema($fields);
            }
            $tabs[] = Tab::make("Tab $t")->schema($sections);
        }

        return $schema
            ->components([
                Select::make('mode')->options(['all' => 'All', 'most' => 'Most', 'none' => 'None'])->live(),
                Tabs::make()->tabs($tabs),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedSchema::make('form')]);
    }
}
