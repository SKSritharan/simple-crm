<?php

namespace App\Mcp\Resources;

use App\Enums\TaskStatus;
use App\Mcp\CrmRecords;
use App\Models\Deal;
use App\Models\Task;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Uri('crm://overview')]
#[MimeType('application/json')]
#[Description('Active record counts, pending and overdue tasks, and pipeline totals by currency and stage.')]
class CrmOverview extends Resource
{
    public function handle(Request $request): Response
    {
        $counts = [];
        foreach (CrmRecords::MODELS as $type => $model) {
            $counts[$type] = $model::query()->count();
        }
        $pending = Task::query()->where('status', TaskStatus::Pending);
        $pipeline = Deal::query()->select('currency', 'stage')
            ->selectRaw('COUNT(*) as deal_count, SUM(amount) as total_amount')
            ->groupBy('currency', 'stage')->orderBy('currency')->orderBy('stage')->get()
            ->map(fn (Deal $deal): array => [
                'currency' => $deal->currency, 'stage' => $deal->stage->value,
                'deal_count' => (int) $deal->getAttribute('deal_count'),
                'total_amount' => number_format((float) $deal->getAttribute('total_amount'), 2, '.', ''),
            ])->all();

        return Response::json([
            'counts' => $counts,
            'pending_tasks' => (clone $pending)->count(),
            'overdue_tasks' => (clone $pending)->where('due_at', '<', now())->count(),
            'pipeline' => $pipeline,
        ]);
    }
}
