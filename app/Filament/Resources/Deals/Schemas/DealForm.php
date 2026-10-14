<?php

namespace App\Filament\Resources\Deals\Schemas;

use App\Enums\DealStage;
use App\Filament\Resources\CrmFields;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DealForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(255),
            CrmFields::company(),
            CrmFields::contact(),
            CrmFields::user('owner_id', 'owner'),
            Select::make('stage')->options(collect(DealStage::cases())->mapWithKeys(fn (DealStage $stage): array => [$stage->value => $stage->name])->all())->required()->default(DealStage::New->value),
            TextInput::make('amount')->numeric()->required()->minValue(0)->maxValue(9999999999999.99)->step(0.01)->rules(['decimal:0,2'])->default(0),
            TextInput::make('currency')->required()->length(3)->regex('/^[A-Za-z]{3}$/')->dehydrateStateUsing(fn (string $state): string => strtoupper($state))->default('USD'),
            DatePicker::make('expected_close_date'),
            DateTimePicker::make('closed_at'),
            Textarea::make('lost_reason')->columnSpanFull(),
        ]);
    }
}
