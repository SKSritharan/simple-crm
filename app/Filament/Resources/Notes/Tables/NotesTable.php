<?php

namespace App\Filament\Resources\Notes\Tables;

use App\Filament\Resources\CrmFields;
use App\Models\Note;
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

class NotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'noteable' => fn (MorphTo $query): MorphTo => $query->withTrashed(),
            ]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('body')->searchable()->limit(80),
                TextColumn::make('author.name')->sortable(),
                TextColumn::make('noteable_type')->label('Related record')->formatStateUsing(fn (Note $record): ?string => CrmFields::parentLabel($record->noteable)),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('author')->relationship('author', 'name')->searchable(),
                SelectFilter::make('noteable_type')->label('Related record type')->options(['company' => 'Company', 'contact' => 'Contact', 'deal' => 'Deal']),
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
