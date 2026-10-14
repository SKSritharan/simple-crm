<?php

namespace App\Filament\Widgets;

use App\Enums\DealStage;
use App\Models\Deal;
use Filament\Widgets\ChartWidget;

class DealStagesChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Deals by stage';

    protected ?string $description = 'Deal counts across the sales lifecycle.';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '280px';

    protected ?string $emptyStateHeading = 'No deals yet';

    protected ?string $emptyStateDescription = 'Create a deal to start tracking your sales pipeline.';

    protected ?array $options = [
        'indexAxis' => 'y',
        'plugins' => ['legend' => ['display' => false]],
        'scales' => ['x' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
    ];

    /** @return array{datasets: array<array{label: string, data: array<int>, backgroundColor: array<string>}>, labels: array<string>} */
    protected function getData(): array
    {
        $counts = Deal::query()->selectRaw('stage, COUNT(*) as total')->groupBy('stage')->pluck('total', 'stage');

        return [
            'datasets' => [[
                'label' => 'Deals',
                'data' => array_map(fn (DealStage $stage): int => (int) ($counts[$stage->value] ?? 0), DealStage::cases()),
                'backgroundColor' => ['#fbbf24', '#f59e0b', '#d97706', '#b45309', '#22c55e', '#ef4444'],
            ]],
            'labels' => array_map(fn (DealStage $stage): string => $stage->name, DealStage::cases()),
        ];
    }

    public function isEmpty(): bool
    {
        return array_sum($this->getCachedData()['datasets'][0]['data']) === 0;
    }

    public function getChartAssistiveContent(): ?string
    {
        $data = $this->getCachedData();

        return implode(', ', array_map(
            fn (string $label, int $count): string => $label.': '.$count,
            $data['labels'],
            $data['datasets'][0]['data'],
        ));
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
