<?php

namespace App\Filament\Resources;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use Filament\Forms\Components\MorphToSelect;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CrmFields
{
    public static function user(string $field, string $relationship): Select
    {
        return Select::make($field)
            ->relationship($relationship, 'name')
            ->searchable()
            ->default(fn (): ?int => auth()->id());
    }

    public static function company(): Select
    {
        return Select::make('company_id')
            ->relationship('company', 'name', fn (Builder $query, ?Model $record): Builder => self::availableRecords($query, $record?->company_id))
            ->searchable();
    }

    public static function contact(): Select
    {
        return Select::make('contact_id')
            ->relationship('contact', 'first_name', fn (Builder $query, ?Model $record): Builder => self::availableRecords($query, $record?->contact_id))
            ->getOptionLabelFromRecordUsing(fn (Contact $record): string => self::contactName($record))
            ->searchable(['first_name', 'last_name', 'email']);
    }

    public static function parent(string $relationship, bool $required = false): MorphToSelect
    {
        return MorphToSelect::make($relationship)
            ->label('Related record')
            ->types([
                self::parentType(Company::class, 'name', $relationship),
                self::parentType(Contact::class, 'first_name', $relationship)
                    ->searchColumns(['first_name', 'last_name', 'email'])
                    ->getOptionLabelFromRecordUsing(fn (Contact $record): string => self::contactName($record)),
                self::parentType(Deal::class, 'title', $relationship),
            ])
            ->searchable()
            ->required($required)
            ->columnSpanFull();
    }

    public static function contactName(Contact $record): string
    {
        return trim($record->first_name.' '.$record->last_name);
    }

    public static function parentLabel(?Model $record): ?string
    {
        return match (true) {
            $record instanceof Company => 'Company: '.$record->name,
            $record instanceof Contact => 'Contact: '.self::contactName($record),
            $record instanceof Deal => 'Deal: '.$record->title,
            default => null,
        };
    }

    /**
     * @param  class-string<Model>  $model
     */
    private static function parentType(string $model, string $titleAttribute, string $relationship): MorphToSelect\Type
    {
        return MorphToSelect\Type::make($model)
            ->titleAttribute($titleAttribute)
            ->modifyOptionsQueryUsing(function (Builder $query, ?Model $record) use ($model, $relationship): Builder {
                $type = $record?->getAttribute($relationship.'_type');
                $currentId = $type === (new $model)->getMorphClass()
                    ? $record?->getAttribute($relationship.'_id')
                    : null;

                return self::availableRecords($query, $currentId);
            });
    }

    private static function availableRecords(Builder $query, ?int $currentId): Builder
    {
        return $query->withTrashed()->where(function (Builder $query) use ($currentId): void {
            $query->whereNull($query->getModel()->getQualifiedDeletedAtColumn());

            if ($currentId !== null) {
                $query->orWhereKey($currentId);
            }
        });
    }
}
