<?php

use App\Ai\Agents\CrmAssistant;
use App\Filament\Pages\AiSettings;
use App\Livewire\CrmChat;
use App\Models\AiSetting;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['app.env' => 'local']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function configureTestAssistant(string $provider = 'openai'): AiSetting
{
    $settings = new AiSetting([
        'provider' => $provider, 'model' => 'test-model', 'api_key' => 'test-only-secret',
    ]);
    $settings->id = 1;
    $settings->save();

    return $settings;
}

test('guests cannot access settings or chat actions', function () {
    $this->get(AiSettings::getUrl())->assertRedirect(Filament::getLoginUrl());
    Livewire::test(CrmChat::class)->assertForbidden();
});

test('authenticated panel pages include the assistant and login does not', function () {
    $this->get(Filament::getLoginUrl())->assertDontSeeLivewire(CrmChat::class);
    $this->actingAs(User::factory()->create());
    $this->get('/admin')->assertOk()->assertSeeLivewire(CrmChat::class);
    $this->get('/admin/companies')->assertOk()->assertSeeLivewire(CrmChat::class);
});

test('shared settings encrypt credentials and never hydrate the saved key', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(AiSettings::class)
        ->set('data.provider', 'openai')->set('data.model', 'test-model')
        ->set('data.api_key', 'test-only-secret')->call('save')
        ->assertHasNoErrors()->assertSet('data.api_key', '')
        ->assertDontSee('test-only-secret');

    expect(DB::table('ai_settings')->value('api_key'))->not->toBe('test-only-secret');
    expect(AiSetting::shared()->api_key)->toBe('test-only-secret');
    expect(AiSetting::shared()->toArray())->not->toHaveKey('api_key');

    $this->actingAs(User::factory()->create());
    Livewire::test(AiSettings::class)->assertSet('data.model', 'test-model')
        ->assertSet('data.api_key', '')->assertDontSee('test-only-secret')
        ->set('data.model', 'another-model')->call('save')->assertHasNoErrors();
    expect(AiSetting::shared()->api_key)->toBe('test-only-secret');
    expect(AiSetting::count())->toBe(1);
});

test('settings require a replacement key when switching providers', function (string $provider) {
    $this->actingAs(User::factory()->create());
    configureTestAssistant();

    Livewire::test(AiSettings::class)->set('data.provider', $provider)
        ->call('save')->assertHasErrors(['data.api_key'])
        ->set('data.api_key', 'replacement-secret')->call('save')->assertHasNoErrors();

    expect(AiSetting::shared()->provider)->toBe($provider);
    expect(AiSetting::shared()->api_key)->toBe('replacement-secret');
})->with(['anthropic', 'openrouter']);

test('settings reject unsupported providers missing models and missing initial keys', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(AiSettings::class)->set('data.provider', 'unsupported')
        ->set('data.model', '')->call('save')->assertHasErrors(['data.provider', 'data.model']);
    Livewire::test(AiSettings::class)->set('data.model', 'test-model')
        ->call('save')->assertHasErrors(['data.api_key']);
    $this->assertDatabaseCount('ai_settings', 0);
});

test('chat requests use shared provider credentials without changing global configuration', function (string $provider) {
    $user = User::factory()->create();
    $this->actingAs($user);
    configureTestAssistant($provider);
    $original = config('ai.providers');
    CrmAssistant::fake(['Here are your CRM records.'])->preventStrayPrompts();

    Livewire::test(CrmChat::class)->set('draft', 'Find companies')->call('send')
        ->assertHasNoErrors()->assertSet('draft', '')->assertSee('Here are your CRM records.');

    CrmAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Find companies' && $prompt->model === 'test-model'
        && $prompt->provider->driver() === $provider
        && $prompt->provider->providerCredentials()['key'] === 'test-only-secret'
    );
    expect(config('ai.providers'))->toBe($original);
    expect(session('crm_chat.'.$user->id))->toHaveCount(2);
})->with(['openai', 'anthropic', 'gemini', 'openrouter']);

test('chat history survives remounts is bounded and is not editable browser state', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    configureTestAssistant();
    $history = [];
    for ($index = 0; $index < 24; $index++) {
        $history[] = ['role' => $index % 2 === 0 ? 'user' : 'assistant', 'content' => 'Message '.$index];
    }
    session()->put('crm_chat.'.$user->id, $history);
    CrmAssistant::fake(['Latest response'])->preventStrayPrompts();

    $chat = Livewire::test(CrmChat::class)->assertSee('Message 23')
        ->set('draft', 'Continue')->call('send')->assertHasNoErrors();
    CrmAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => count($prompt->messages) === 20 && $prompt->messages[0]->content === 'Message 4'
    );
    expect($chat->snapshot['data'])->not->toHaveKey('messages');
    expect(session('crm_chat.'.$user->id))->toHaveCount(20);
    Livewire::test(CrmChat::class)->assertSee('Latest response')->call('clearChat')
        ->assertDontSee('Latest response');
    expect(session('crm_chat.'.$user->id))->toBeNull();
});

test('chat history is isolated by user and discarded when the session expires', function () {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    session()->put('crm_chat.'.$firstUser->id, [['role' => 'user', 'content' => 'Private first chat']]);
    $this->actingAs($secondUser);
    Livewire::test(CrmChat::class)->assertDontSee('Private first chat');
    $this->actingAs($firstUser);
    Livewire::test(CrmChat::class)->assertSee('Private first chat');
    session()->invalidate();
    Livewire::test(CrmChat::class)->assertDontSee('Private first chat');
});

test('logging out clears chat history', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    session()->put('crm_chat.'.$user->id, [['role' => 'user', 'content' => 'Private chat']]);
    $this->post(route('filament.admin.auth.logout'))->assertRedirect();
    expect(session('crm_chat.'.$user->id))->toBeNull();
});

test('chat rejects empty and oversized input without prompting', function (string $draft) {
    $this->actingAs(User::factory()->create());
    CrmAssistant::fake()->preventStrayPrompts();

    Livewire::test(CrmChat::class)->set('draft', $draft)->call('send')->assertHasErrors(['draft']);
    CrmAssistant::assertNeverPrompted();
})->with(['empty' => '', 'whitespace' => '   ', 'too long' => str_repeat('a', 4001)]);

test('unconfigured chat links to settings and preserves the draft', function () {
    $this->actingAs(User::factory()->create());
    CrmAssistant::fake()->preventStrayPrompts();
    Livewire::test(CrmChat::class)->assertSee('Configure AI Settings')
        ->set('draft', 'Find companies')->call('send')->assertHasErrors(['draft'])
        ->assertSet('draft', 'Find companies');
    CrmAssistant::assertNeverPrompted();
});

test('chat throttles the eleventh request per user', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    configureTestAssistant();
    CrmAssistant::fake()->preventStrayPrompts();
    for ($index = 0; $index < 10; $index++) {
        RateLimiter::hit('crm-chat:'.$user->id, 60);
    }
    Livewire::test(CrmChat::class)->set('draft', 'Find companies')->call('send')
        ->assertHasErrors(['draft'])->assertSee('too many messages');
    CrmAssistant::assertNeverPrompted();
});

test('overlapping chat requests and clearing are rejected', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    configureTestAssistant();
    CrmAssistant::fake()->preventStrayPrompts();
    session()->put('crm_chat.'.$user->id, [['role' => 'user', 'content' => 'Keep this']]);
    $lock = Cache::lock('crm-chat:user:'.$user->id, 1200);
    $lock->get();
    try {
        Livewire::test(CrmChat::class)->set('draft', 'Find companies')->call('send')
            ->assertHasErrors(['draft'])->assertSee('already in progress')
            ->call('clearChat')->assertSee('Keep this');
        CrmAssistant::assertNeverPrompted();
    } finally {
        $lock->release();
    }
});

test('provider failures hide sensitive details preserve history and release the lock', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    configureTestAssistant();
    CrmAssistant::fake([fn () => throw new RuntimeException('test-only-secret provider error')]);

    $chat = Livewire::test(CrmChat::class)->set('draft', 'Find companies')->call('send')
        ->assertHasErrors(['draft'])->assertSet('draft', 'Find companies')
        ->assertDontSee('test-only-secret')->assertSee('could not respond');
    expect(session('crm_chat.'.$user->id))->toBeNull();
    CrmAssistant::fake(['Recovered']);
    $chat->call('send')->assertHasNoErrors()->assertSee('Recovered');
});

test('agent can execute CRM read tools but cannot execute a write tool', function () {
    $company = Company::factory()->create(['name' => 'Acme']);
    $before = $company->fresh()->getAttributes();
    CrmAssistant::fake([
        new ToolCall('read-company', 'get-crm-record', ['type' => 'company', 'id' => $company->id]),
        'Found Acme.',
    ])->preventStrayPrompts();
    $response = (new CrmAssistant)->prompt('Find Acme', provider: 'openai', model: 'test-model');
    expect($response->toolResults)->toHaveCount(1);
    expect($response->toolResults->first()->result)->toContain('Acme');
    expect($company->fresh()->getAttributes())->toBe($before);

    CrmAssistant::fake([new ToolCall('write-company', 'update-company', ['id' => $company->id, 'name' => 'Changed'])])
        ->preventStrayPrompts();
    expect(fn () => (new CrmAssistant)->prompt('Update Acme', provider: 'openai', model: 'test-model'))
        ->toThrow(NoSuchToolException::class);
    expect($company->fresh()->getAttributes())->toBe($before);
});

test('assistant replies render markdown tables and lists while user messages remain plain text', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    session()->put('crm_chat.'.$user->id, [
        ['role' => 'user', 'content' => '**My question**'],
        ['role' => 'assistant', 'content' => "## CRM summary\n\n**Available data**\n\n| Record | Count |\n| --- | --- |\n| Tasks | 3 |\n\n- Follow up\n\n*End of summary.*"],
    ]);

    Livewire::test(CrmChat::class)->assertSeeHtml('<h2>CRM summary</h2>')
        ->assertSeeHtml('<strong>Available data</strong>')->assertSeeHtml('<table>')
        ->assertSeeHtml('<td>Tasks</td>')->assertSeeHtml('<li>Follow up</li>')
        ->assertSeeHtml('<em>End of summary.</em>')
        ->assertSee('**My question**')->assertDontSeeHtml('<strong>My question</strong>');
});

test('assistant markdown does not allow unsafe links', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    session()->put('crm_chat.'.$user->id, [
        ['role' => 'assistant', 'content' => '[Click me](javascript:alert%281%29)'],
    ]);

    Livewire::test(CrmChat::class)->assertSee('Click me')->assertDontSeeHtml('href="javascript:');
});

test('assistant responses are escaped and denied users cannot call actions', function () {
    $this->actingAs(User::factory()->create());
    configureTestAssistant();
    CrmAssistant::fake(['<script>alert("unsafe")</script>']);
    Livewire::test(CrmChat::class)->set('draft', 'Hello')->call('send')
        ->assertSeeHtml('&lt;script&gt;')->assertDontSeeHtml('<script>alert("unsafe")</script>');
    Gate::define('use-ai-assistant', fn (User $user): bool => false);
    Livewire::test(CrmChat::class)->assertForbidden();
    Livewire::test(AiSettings::class)->assertForbidden();
});
