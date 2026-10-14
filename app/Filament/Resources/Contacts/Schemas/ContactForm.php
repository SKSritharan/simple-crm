<?php

namespace App\Filament\Resources\Contacts\Schemas;

use App\Filament\Resources\CrmFields;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ContactForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('first_name')->required()->maxLength(255),
            TextInput::make('last_name')->maxLength(255),
            TextInput::make('email')->email()->maxLength(255),
            TextInput::make('phone')->type('tel')->maxLength(255),
            TextInput::make('job_title')->maxLength(255),
            CrmFields::company(),
            CrmFields::user('owner_id', 'owner'),
        ]);
    }
}
