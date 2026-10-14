<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Enums\TaskStatus;
use App\Filament\Resources\CrmFields;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TaskForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(255),
            Textarea::make('description')->columnSpanFull(),
            CrmFields::user('assigned_to_id', 'assignedTo')->label('Assigned to'),
            Select::make('status')->options(collect(TaskStatus::cases())->mapWithKeys(fn (TaskStatus $status): array => [$status->value => $status->name])->all())->required()->default(TaskStatus::Pending->value),
            DateTimePicker::make('due_at'),
            DateTimePicker::make('completed_at'),
            CrmFields::parent('taskable'),
        ]);
    }
}
