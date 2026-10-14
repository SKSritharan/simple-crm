<?php

namespace App\Filament\Widgets;

use App\Enums\DealStage;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealsClosingSoon extends TableWidget
{
    protected static ?int $sort = 5;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Deals closing soon')
            ->description('Overdue closing dates and opportunities due within 30 days.')
            ->query(fn (): Builder => Deal::query()
                ->whereIn('stage', DealStage::openStages())
                ->where('expected_close_date', '<=', now()->addDays(30)->endOfDay())
                ->with(['company' => fn (BelongsTo $query): BelongsTo => $query->withTrashed()])
                ->orderBy('expected_close_date')
                ->orderBy('id')
                ->limit(5))
            ->columns([
                TextColumn::make('title')->wrap(),
                TextColumn::make('company.name')->label('Company')->placeholder('No company')->wrap(),
                TextColumn::make('stage')->badge()->formatStateUsing(fn (DealStage $state): string => $state->name)->color('primary'),
                TextColumn::make('amount')->formatStateUsing(fn (Deal $record): string => $record->currency.' '.number_format((float) $record->amount, 2)),
                TextColumn::make('expected_close_date')->label('Closes')->date('M j, Y')
                    ->color(fn (Deal $record): ?string => $record->expected_close_date->lt(today()) ? 'danger' : null),
            ])
            ->paginated(false)
            ->recordUrl(fn (Deal $record): string => DealResource::getUrl('edit', ['record' => $record]))
            ->emptyStateHeading('No deals closing soon')
            ->emptyStateDescription('Open deals with an expected close date within 30 days or overdue appear here.')
            ->headerActions([
                Action::make('viewDeals')->label('View all deals')->url(DealResource::getUrl())->link(),
            ]);
    }
}
