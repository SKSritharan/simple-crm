<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class TasksNeedingAttention extends TableWidget
{
    protected static ?int $sort = 4;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Tasks needing attention')
            ->description('Overdue tasks and deadlines in the next 7 days.')
            ->query(fn (): Builder => Task::query()
                ->where('status', TaskStatus::Pending)
                ->where('due_at', '<=', now()->addDays(7)->endOfDay())
                ->with('assignedTo')
                ->orderBy('due_at')
                ->orderBy('id')
                ->limit(5))
            ->columns([
                TextColumn::make('title')->wrap(),
                TextColumn::make('assignedTo.name')->label('Assigned to')->placeholder('Unassigned'),
                TextColumn::make('due_at')->label('Due')->dateTime('M j, Y H:i')->badge()
                    ->color(fn (Task $record): string => $record->due_at->isPast() ? 'danger' : 'warning'),
            ])
            ->paginated(false)
            ->recordUrl(fn (Task $record): string => TaskResource::getUrl('edit', ['record' => $record]))
            ->emptyStateHeading('No tasks need attention')
            ->emptyStateDescription('You have no pending tasks due in the next 7 days or overdue.')
            ->headerActions([
                Action::make('viewTasks')->label('View all tasks')->url(TaskResource::getUrl())->link(),
            ]);
    }
}
