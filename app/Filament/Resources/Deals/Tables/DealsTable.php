<?php

namespace App\Filament\Resources\Deals\Tables;

use App\Enums\DealStage;
use App\Filament\Resources\CrmFields;
use App\Models\Contact;
use App\Models\Deal;
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

class DealsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'company' => fn (Builder|BelongsTo $query): Builder|BelongsTo => $query->withTrashed(),
                'contact' => fn (Builder|BelongsTo $query): Builder|BelongsTo => $query->withTrashed(),
            ]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('company.name')->sortable(),
                TextColumn::make('contact.first_name')->label('Contact')->formatStateUsing(fn (Deal $record): ?string => $record->contact ? CrmFields::contactName($record->contact) : null),
                TextColumn::make('owner.name')->label('Owner')->sortable(),
                TextColumn::make('stage')->badge()->formatStateUsing(fn (DealStage $state): string => $state->name)->color(fn (DealStage $state): string => match ($state) {
                    DealStage::Won => 'success', DealStage::Lost => 'danger', default => 'info'
                })->sortable(),
                TextColumn::make('amount')->money(fn (Deal $record): string => $record->currency)->sortable(),
                TextColumn::make('expected_close_date')->date()->sortable(),
                TextColumn::make('closed_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('company')->relationship('company', 'name')->searchable(),
                SelectFilter::make('contact')->relationship('contact', 'first_name')->getOptionLabelFromRecordUsing(fn (Contact $record): string => CrmFields::contactName($record))->searchable(),
                SelectFilter::make('owner')->relationship('owner', 'name')->searchable(),
                SelectFilter::make('stage')->options(collect(DealStage::cases())->mapWithKeys(fn (DealStage $stage): array => [$stage->value => $stage->name])->all()),
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
