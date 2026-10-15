<?php

use App\Ai\Agents\CrmAssistant;
use App\Livewire\CrmChat;
use App\Models\AiSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Responses\Data\ToolCall;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['app.env' => 'local']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

function configureCreditTestAssistant(): void
{
    $settings = new AiSetting(['provider' => 'openai', 'model' => 'test-model', 'api_key' => 'test-only-key']);
    $settings->id = 1;
    $settings->save();
}

test('new users receive thirty credits and one reply with tool calls costs one credit', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    configureCreditTestAssistant();
    CrmAssistant::fake([new ToolCall('search', 'search-crm', ['type' => 'company']), 'No companies found.'])
        ->preventStrayPrompts();

    Livewire::test(CrmChat::class)->assertSee('30 of 30 credits remaining')
        ->assertSee('November 1, 2026')
        ->set('draft', 'Find companies')->call('send')->assertHasNoErrors()
        ->assertSee('29 of 30 credits remaining');

    $this->assertDatabaseHas('users', ['id' => $user->id, 'ai_credit_month' => '2026-10', 'ai_credits_used' => 1]);
    expect($user->fresh()->remainingAiCredits())->toBe(29);
    CrmAssistant::assertPrompted('Find companies');
});

test('the thirtieth reply succeeds and the thirty first request never reaches the provider', function () {
    $user = User::factory()->create(['ai_credit_month' => '2026-10', 'ai_credits_used' => 29]);
    $this->actingAs($user);
    configureCreditTestAssistant();
    CrmAssistant::fake(['Last reply'])->preventStrayPrompts();

    $chat = Livewire::test(CrmChat::class)->set('draft', 'Last question')->call('send')
        ->assertHasNoErrors()->assertSee('Last reply')->assertSee('0 of 30 credits remaining')
        ->assertSee('You have used all your AI credits');
    expect($user->fresh()->ai_credits_used)->toBe(30);

    CrmAssistant::fake()->preventStrayPrompts();
    $chat->set('draft', 'One more question')->call('send')
        ->assertHasErrors(['draft'])->assertSet('draft', 'One more question')
        ->assertSee('Last reply');
    CrmAssistant::assertNotPrompted('One more question');
    expect($user->fresh()->ai_credits_used)->toBe(30);
});

test('failed and empty replies preserve the balance draft and previous history', function (string $failure) {
    $user = User::factory()->create(['ai_credit_month' => '2026-10', 'ai_credits_used' => 5]);
    $this->actingAs($user);
    configureCreditTestAssistant();
    session()->put('crm_chat.'.$user->id, [['role' => 'assistant', 'content' => 'Previous reply']]);
    CrmAssistant::fake($failure === 'exception' ? [fn () => throw new RuntimeException('Provider unavailable')] : [$failure])
        ->preventStrayPrompts();

    Livewire::test(CrmChat::class)->set('draft', 'Keep my question')->call('send')
        ->assertHasErrors(['draft'])->assertSet('draft', 'Keep my question')
        ->assertSee('Previous reply')->assertSee('25 of 30 credits remaining');

    expect($user->fresh()->ai_credits_used)->toBe(5);
    expect(session('crm_chat.'.$user->id))->toHaveCount(1);
})->with(['exception', '', '   ']);

test('balances are per user and survive clearing logout session changes and provider changes', function () {
    $user = User::factory()->create(['ai_credit_month' => '2026-10', 'ai_credits_used' => 8]);
    $other = User::factory()->create();
    $this->actingAs($user);
    configureCreditTestAssistant();
    CrmAssistant::fake(['First reply', 'Second reply'])->preventStrayPrompts();

    Livewire::test(CrmChat::class)->set('draft', 'Question')->call('send')->assertHasNoErrors()
        ->call('clearChat')->assertSee('21 of 30 credits remaining');
    $this->post(route('filament.admin.auth.logout'))->assertRedirect();
    $this->actingAs($user->fresh());
    AiSetting::shared()->update(['provider' => 'gemini']);
    Livewire::test(CrmChat::class)->assertSee('21 of 30 credits remaining')
        ->set('draft', 'Another question')->call('send')->assertHasNoErrors()
        ->assertSee('20 of 30 credits remaining');
    expect($user->fresh()->ai_credits_used)->toBe(10);

    $this->actingAs($other);
    Livewire::test(CrmChat::class)->assertSee('30 of 30 credits remaining');
    expect($other->fresh()->ai_credits_used)->toBe(0);
});

test('an exhausted user can read and clear existing history', function () {
    $user = User::factory()->create(['ai_credit_month' => '2026-10', 'ai_credits_used' => 30]);
    $this->actingAs($user);
    session()->put('crm_chat.'.$user->id, [['role' => 'assistant', 'content' => 'Saved session reply']]);

    Livewire::test(CrmChat::class)->assertSee('Saved session reply')
        ->call('clearChat')->assertHasNoErrors()->assertDontSee('Saved session reply')
        ->assertSee('0 of 30 credits remaining');
    expect($user->fresh()->ai_credits_used)->toBe(30);
});

test('credits renew at Colombo midnight including the new year without rollover', function (string $month, string $boundary, string $nextMonth) {
    $user = User::factory()->create(['ai_credit_month' => $month, 'ai_credits_used' => 30]);
    $this->actingAs($user);
    configureCreditTestAssistant();
    $this->travelTo(CarbonImmutable::parse($boundary, 'UTC')->subSecond());
    Livewire::test(CrmChat::class)->assertSee('0 of 30 credits remaining');

    $this->travelTo(CarbonImmutable::parse($boundary, 'UTC'));
    CrmAssistant::fake(['New month reply'])->preventStrayPrompts();
    Livewire::test(CrmChat::class)->assertSee('30 of 30 credits remaining')
        ->set('draft', 'New question')->call('send')->assertHasNoErrors()
        ->assertSee('29 of 30 credits remaining');
    $this->assertDatabaseHas('users', ['id' => $user->id, 'ai_credit_month' => $nextMonth, 'ai_credits_used' => 1]);
    expect(config('app.timezone'))->toBe('UTC');
})->with([
    ['2026-10', '2026-10-31 18:30:00', '2026-11'],
    ['2026-12', '2026-12-31 18:30:00', '2027-01'],
]);

test('unused credits expire and failed renewal attempts do not change stored usage', function () {
    $user = User::factory()->create(['ai_credit_month' => '2026-09', 'ai_credits_used' => 4]);
    $this->actingAs($user);
    configureCreditTestAssistant();
    CrmAssistant::fake([fn () => throw new RuntimeException('Unavailable')]);

    Livewire::test(CrmChat::class)->assertSee('30 of 30 credits remaining')
        ->set('draft', 'Question')->call('send')->assertHasErrors(['draft'])
        ->assertSee('30 of 30 credits remaining');
    $this->assertDatabaseHas('users', ['id' => $user->id, 'ai_credit_month' => '2026-09', 'ai_credits_used' => 4]);
});

test('requests crossing midnight charge the month in which they started', function () {
    $user = User::factory()->create(['ai_credit_month' => '2026-12', 'ai_credits_used' => 29]);
    $this->actingAs($user);
    configureCreditTestAssistant();
    $this->travelTo(CarbonImmutable::parse('2026-12-31 18:29:59', 'UTC'));
    CrmAssistant::fake([function () {
        $this->travelTo(CarbonImmutable::parse('2026-12-31 18:30:01', 'UTC'));

        return 'Reply after midnight';
    }])->preventStrayPrompts();

    Livewire::test(CrmChat::class)->set('draft', 'Question before midnight')->call('send')
        ->assertHasNoErrors()->assertSee('Reply after midnight')->assertSee('30 of 30 credits remaining');
    $this->assertDatabaseHas('users', ['id' => $user->id, 'ai_credit_month' => '2026-12', 'ai_credits_used' => 30]);

    CrmAssistant::fake(['January reply']);
    Livewire::test(CrmChat::class)->set('draft', 'January question')->call('send')
        ->assertHasNoErrors()->assertSee('29 of 30 credits remaining');
    $this->assertDatabaseHas('users', ['id' => $user->id, 'ai_credit_month' => '2027-01', 'ai_credits_used' => 1]);
});

test('a request from another session cannot bypass the per user lock', function () {
    $user = User::factory()->create(['ai_credit_month' => '2026-10', 'ai_credits_used' => 29]);
    $this->actingAs($user);
    configureCreditTestAssistant();
    CrmAssistant::fake()->preventStrayPrompts();
    $lock = Cache::lock('crm-chat:user:'.$user->id, 1200);
    $lock->get();
    session()->invalidate();

    try {
        Livewire::test(CrmChat::class)->set('draft', 'Concurrent question')->call('send')
            ->assertHasErrors(['draft'])->assertSee('already in progress');
        CrmAssistant::assertNeverPrompted();
        expect($user->fresh()->ai_credits_used)->toBe(29);
    } finally {
        $lock->release();
    }
});

test('a reply is not displayed or charged when usage persistence fails', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    configureCreditTestAssistant();
    CrmAssistant::fake(['Undelivered reply']);
    User::saving(function (User $savingUser): void {
        if ($savingUser->isDirty('ai_credits_used')) {
            throw new RuntimeException('Cannot save usage');
        }
    });

    Livewire::test(CrmChat::class)->set('draft', 'Keep question')->call('send')
        ->assertHasErrors(['draft'])->assertSet('draft', 'Keep question')
        ->assertDontSee('Undelivered reply');
    expect($user->fresh()->ai_credits_used)->toBe(0);
});

test('credit accounting fields cannot be mass assigned', function () {
    $user = User::factory()->create(['ai_credit_month' => '2026-10', 'ai_credits_used' => 30]);

    $user->fill(['ai_credit_month' => null, 'ai_credits_used' => 0])->save();

    expect($user->fresh()->remainingAiCredits())->toBe(0);
});
