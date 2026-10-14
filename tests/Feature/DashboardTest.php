<?php

use App\Enums\DealStage;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Widgets\CrmOverview;
use App\Filament\Widgets\DealsClosingSoon;
use App\Filament\Widgets\DealStagesChart;
use App\Filament\Widgets\PipelineSummary;
use App\Filament\Widgets\TasksNeedingAttention;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['app.env' => 'local']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::withoutLazyLoading();
    $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
});

test('guests are redirected to login from the dashboard', function () {
    $this->get(Dashboard::getUrl())->assertRedirect(Filament::getLoginUrl());
});

test('signed in users see the CRM dashboard widgets instead of default widgets', function () {
    $this->actingAs(User::factory()->create());

    $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertSeeLivewire(CrmOverview::class)
        ->assertSeeLivewire(DealStagesChart::class)
        ->assertSeeLivewire(PipelineSummary::class)
        ->assertSeeLivewire(TasksNeedingAttention::class)
        ->assertSeeLivewire(DealsClosingSoon::class)
        ->assertDontSeeLivewire(AccountWidget::class)
        ->assertDontSeeLivewire(FilamentInfoWidget::class);
});

test('summary cards count the whole active CRM and link to resource lists', function () {
    $this->actingAs(User::factory()->create());
    $otherUser = User::factory()->create();
    Company::factory()->count(2)->for($otherUser, 'owner')->create();
    Contact::factory()->count(3)->for($otherUser, 'owner')->create();
    Company::factory()->trashed()->create();
    Contact::factory()->trashed()->create();
    Deal::factory()->count(4)->sequence(
        ['stage' => DealStage::New], ['stage' => DealStage::Qualified],
        ['stage' => DealStage::Proposal], ['stage' => DealStage::Negotiation],
    )->for($otherUser, 'owner')->create();
    Deal::factory()->won()->create();
    Deal::factory()->lost()->create();
    Deal::factory()->trashed()->create();
    Task::factory()->for($otherUser, 'assignedTo')->create(['due_at' => '2026-10-14 11:59:59']);
    Task::factory()->create(['due_at' => '2026-10-14 12:00:00']);
    Task::factory()->create(['due_at' => '2026-10-22 00:00:00']);
    Task::factory()->create(['due_at' => null]);
    Task::factory()->completed()->create(['due_at' => '2026-10-13 12:00:00']);
    Task::factory()->cancelled()->create(['due_at' => '2026-10-13 12:00:00']);
    Task::factory()->trashed()->create(['due_at' => '2026-10-13 12:00:00']);

    $widget = Livewire::test(CrmOverview::class)
        ->assertSee('1 overdue')
        ->assertSeeHtml(CompanyResource::getUrl())
        ->assertSeeHtml(ContactResource::getUrl())
        ->assertSeeHtml(DealResource::getUrl())
        ->assertSeeHtml(TaskResource::getUrl());

    $stats = $widget->instance()->getSchema('content')->getComponents()[0]->getChildSchema()->getComponents();
    expect(array_map(fn ($stat) => $stat->getValue(), $stats))->toBe([2, 3, 4, 4]);
});

test('deal chart shows all stage counts including zero stages and excludes deleted deals', function () {
    $this->actingAs(User::factory()->create());
    Deal::factory()->count(2)->create();
    Deal::factory()->create(['stage' => DealStage::Proposal]);
    Deal::factory()->won()->create();
    Deal::factory()->lost()->count(3)->create();
    Deal::factory()->trashed()->create();

    Livewire::test(DealStagesChart::class)
        ->assertSee('New: 2, Qualified: 0, Proposal: 1, Negotiation: 0, Won: 1, Lost: 3')
        ->assertDontSee('No deals yet');
});

test('pipeline values sum only open active deals and keep currencies separate', function () {
    $this->actingAs(User::factory()->create());
    Deal::factory()->create(['amount' => '1000.10', 'currency' => 'USD']);
    Deal::factory()->create(['amount' => '234.56', 'currency' => 'USD', 'stage' => DealStage::Negotiation]);
    Deal::factory()->create(['amount' => '7500.75', 'currency' => 'LKR', 'stage' => DealStage::Proposal]);
    Deal::factory()->create(['amount' => '0.00', 'currency' => 'EUR', 'stage' => DealStage::Qualified]);
    Deal::factory()->won()->create(['amount' => '999.99', 'currency' => 'USD']);
    Deal::factory()->lost()->create(['amount' => '999.99', 'currency' => 'LKR']);
    Deal::factory()->trashed()->create(['amount' => '999.99', 'currency' => 'EUR']);

    $widget = Livewire::test(PipelineSummary::class)
        ->assertSee('1,234.66')->assertSee('7,500.75')->assertSee('0.00')
        ->assertSeeHtml(DealResource::getUrl());

    $records = $widget->instance()->getTableRecords();
    expect($records->keys()->all())->toBe(['EUR', 'LKR', 'USD']);
    expect((int) $records['USD']['deal_count'])->toBe(2);
    expect((int) $records['LKR']['deal_count'])->toBe(1);
});

test('attention tasks include overdue and seven day boundary but exclude other tasks', function () {
    $this->actingAs(User::factory()->create());
    $otherUser = User::factory()->create(['name' => 'Other teammate']);
    $boundary = Task::factory()->create(['title' => 'Boundary task', 'due_at' => '2026-10-21 23:59:59']);
    $overdue = Task::factory()->for($otherUser, 'assignedTo')->create(['title' => 'Overdue task', 'due_at' => '2026-10-13 12:00:00']);
    $today = Task::factory()->create(['title' => 'Today task', 'due_at' => '2026-10-14 12:00:00']);
    $excluded = collect([
        Task::factory()->create(['due_at' => '2026-10-22 00:00:00']),
        Task::factory()->create(['due_at' => null]),
        Task::factory()->completed()->create(['due_at' => '2026-10-13 12:00:00']),
        Task::factory()->cancelled()->create(['due_at' => '2026-10-13 12:00:00']),
        Task::factory()->trashed()->create(['due_at' => '2026-10-13 12:00:00']),
    ]);

    $widget = Livewire::test(TasksNeedingAttention::class)
        ->assertCanSeeTableRecords([$overdue, $today, $boundary], inOrder: true)
        ->assertCanNotSeeTableRecords($excluded)
        ->assertSee('Other teammate')->assertSee('Unassigned')
        ->assertSeeHtml(TaskResource::getUrl('edit', ['record' => $overdue]))
        ->assertSeeHtml(TaskResource::getUrl());

    $column = $widget->instance()->getTable()->getColumn('due_at');
    expect($column->record($overdue)->getColor(null))->toBe('danger');
    expect($column->record($today)->getColor(null))->toBe('warning');
});

test('closing deals include overdue and thirty day boundary but exclude closed undated and deleted deals', function () {
    $this->actingAs(User::factory()->create());
    $company = Company::factory()->trashed()->create(['name' => 'Archived customer']);
    $boundary = Deal::factory()->create(['expected_close_date' => '2026-11-13']);
    $overdue = Deal::factory()->for($company)->for(User::factory(), 'owner')->create([
        'expected_close_date' => '2026-10-13', 'amount' => '1234.56', 'currency' => 'LKR',
    ]);
    $today = Deal::factory()->create(['expected_close_date' => '2026-10-14']);
    $excluded = collect([
        Deal::factory()->create(['expected_close_date' => '2026-11-14']),
        Deal::factory()->create(['expected_close_date' => null]),
        Deal::factory()->won()->create(['expected_close_date' => '2026-10-13']),
        Deal::factory()->lost()->create(['expected_close_date' => '2026-10-13']),
        Deal::factory()->trashed()->create(['expected_close_date' => '2026-10-13']),
    ]);

    $widget = Livewire::test(DealsClosingSoon::class)
        ->assertCanSeeTableRecords([$overdue, $today, $boundary], inOrder: true)
        ->assertCanNotSeeTableRecords($excluded)
        ->assertSee('Archived customer')->assertSee('LKR 1,234.56')
        ->assertSeeHtml(DealResource::getUrl('edit', ['record' => $overdue]))
        ->assertSeeHtml(DealResource::getUrl());

    $column = $widget->instance()->getTable()->getColumn('expected_close_date');
    expect($column->record($overdue)->getColor(null))->toBe('danger');
    expect($column->record($today)->getColor(null))->toBeNull();
});

test('deadline tables show only the five earliest records with stable ordering', function (string $widgetClass, string $modelClass, string $dateField) {
    $this->actingAs(User::factory()->create());
    $later = $modelClass::factory()->create([$dateField => '2026-10-20 00:00:00']);
    $earlier = $modelClass::factory()->count(5)->create([$dateField => '2026-10-15 00:00:00']);

    $widget = Livewire::test($widgetClass)
        ->assertCanSeeTableRecords($earlier, inOrder: true)
        ->assertCanNotSeeTableRecords([$later]);

    expect($widget->instance()->getTableRecords())->toHaveCount(5);
})->with([
    'tasks' => [TasksNeedingAttention::class, Task::class, 'due_at'],
    'deals' => [DealsClosingSoon::class, Deal::class, 'expected_close_date'],
]);

test('empty CRM renders zero totals and helpful widget empty states', function () {
    $this->actingAs(User::factory()->create());

    $widget = Livewire::test(CrmOverview::class)->assertSee('0 overdue');
    $stats = $widget->instance()->getSchema('content')->getComponents()[0]->getChildSchema()->getComponents();
    expect(array_map(fn ($stat) => $stat->getValue(), $stats))->toBe([0, 0, 0, 0]);

    Livewire::test(DealStagesChart::class)->assertSee('No deals yet');
    Livewire::test(PipelineSummary::class)->assertSee('No open deals');
    Livewire::test(TasksNeedingAttention::class)->assertSee('No tasks need attention');
    Livewire::test(DealsClosingSoon::class)->assertSee('No deals closing soon');
});
