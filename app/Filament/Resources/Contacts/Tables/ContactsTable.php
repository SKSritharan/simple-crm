<?php

namespace App\Filament\Resources\Contacts\Tables;

use App\Filament\Resources\CrmFields;
use App\Models\Contact;
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
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'company' => fn (Builder|BelongsTo $query): Builder|BelongsTo => $query->withTrashed(),
            ]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('first_name')->label('Name')->formatStateUsing(fn (Contact $record): string => CrmFields::contactName($record))->searchable(['first_name', 'last_name'])->sortable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('phone')->searchable(),
                TextColumn::make('job_title')->searchable()->toggleable(),
                TextColumn::make('company.name')->sortable(),
                TextColumn::make('owner.name')->label('Owner')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('company')->relationship('company', 'name')->searchable(),
                SelectFilter::make('owner')->relationship('owner', 'name')->searchable(),
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
