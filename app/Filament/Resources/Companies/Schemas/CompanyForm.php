<?php

namespace App\Filament\Resources\Companies\Schemas;

use App\Filament\Resources\CrmFields;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('website')->url()->maxLength(255),
            TextInput::make('email')->email()->maxLength(255),
            TextInput::make('phone')->type('tel')->maxLength(255),
            Textarea::make('address')->columnSpanFull(),
            CrmFields::user('owner_id', 'owner'),
        ]);
    }
}
