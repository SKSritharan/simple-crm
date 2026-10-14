<?php

namespace App\Filament\Resources\Tasks\Tables;

use App\Enums\TaskStatus;
use App\Filament\Resources\CrmFields;
use App\Models\Task;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'taskable' => fn (MorphTo $query): MorphTo => $query->withTrashed(),
            ]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('assignedTo.name')->label('Assigned to')->sortable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (TaskStatus $state): string => $state->name)->color(fn (TaskStatus $state): string => match ($state) {
                    TaskStatus::Completed => 'success', TaskStatus::Cancelled => 'gray', default => 'warning'
                })->sortable(),
                TextColumn::make('taskable_type')->label('Related record')->formatStateUsing(fn (Task $record): ?string => CrmFields::parentLabel($record->taskable)),
                TextColumn::make('due_at')->dateTime()->sortable(),
                TextColumn::make('completed_at')->dateTime()->sortable()->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('assignedTo')->label('Assigned to')->relationship('assignedTo', 'name')->searchable(),
                SelectFilter::make('status')->options(collect(TaskStatus::cases())->mapWithKeys(fn (TaskStatus $status): array => [$status->value => $status->name])->all()),
                SelectFilter::make('taskable_type')->label('Related record type')->options(['company' => 'Company', 'contact' => 'Contact', 'deal' => 'Deal']),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
