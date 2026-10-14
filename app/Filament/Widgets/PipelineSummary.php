<?php

namespace App\Filament\Widgets;

use App\Enums\DealStage;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class PipelineSummary extends TableWidget
{
    protected static ?int $sort = 3;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Open pipeline')
            ->description('Active opportunities and their value, separated by currency.')
            ->records(fn (): array => Deal::query()
                ->whereIn('stage', DealStage::openStages())
                ->selectRaw('currency, COUNT(*) as deal_count, SUM(amount) as total_amount')
                ->groupBy('currency')
                ->orderBy('currency')
                ->toBase()
                ->get()
                ->mapWithKeys(fn (object $record): array => [$record->currency => [
                    'currency' => $record->currency,
                    'deal_count' => $record->deal_count,
                    'total_amount' => $record->total_amount,
                ]])
                ->all())
            ->columns([
                TextColumn::make('currency')->label('Currency')->badge()->color('primary'),
                TextColumn::make('deal_count')->label('Open deals')->numeric(),
                TextColumn::make('total_amount')->label('Pipeline value')->numeric(decimalPlaces: 2),
            ])
            ->paginated(false)
            ->emptyStateHeading('No open deals')
            ->emptyStateDescription('New, qualified, proposal, and negotiation deals appear here.')
            ->headerActions([
                Action::make('viewDeals')->label('View all deals')->url(DealResource::getUrl())->link(),
            ]);
    }
}
