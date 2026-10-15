<?php

namespace App\Filament\Widgets;

use App\Enums\DealStage;
use App\Enums\TaskStatus;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CrmOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    protected int|array|null $columns = ['default' => 1, 'md' => 2, 'xl' => 4];

    /** @return array<Stat> */
    protected function getStats(): array
    {
        $pendingTasks = Task::query()->where('status', TaskStatus::Pending);
        $overdueTasks = (clone $pendingTasks)->where('due_at', '<', now())->count();

        return [
            Stat::make('Companies', Company::query()->count())
                ->description('Customer organizations')
                ->icon(Heroicon::OutlinedBuildingOffice2)
                ->url(CompanyResource::getUrl()),
            Stat::make('Contacts', Contact::query()->count())
                ->description('People in your CRM')
                ->icon(Heroicon::OutlinedUsers)
                ->url(ContactResource::getUrl()),
            Stat::make('Open deals', Deal::query()->whereIn('stage', DealStage::openStages())->count())
                ->description('Active sales opportunities')
                ->icon(Heroicon::OutlinedCurrencyDollar)
                ->color('primary')
                ->url(DealResource::getUrl()),
            Stat::make('Pending tasks', $pendingTasks->count())
                ->description($overdueTasks.' overdue')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color($overdueTasks > 0 ? 'danger' : 'primary')
                ->url(TaskResource::getUrl()),
        ];
    }
}
