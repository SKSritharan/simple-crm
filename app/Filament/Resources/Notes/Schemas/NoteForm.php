<?php

namespace App\Filament\Resources\Notes\Schemas;

use App\Filament\Resources\CrmFields;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class NoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('body')->required()->columnSpanFull(),
            CrmFields::user('author_id', 'author'),
            CrmFields::parent('noteable', required: true),
        ]);
    }
}
