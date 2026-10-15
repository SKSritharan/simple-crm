<?php

use App\Ai\Agents\CrmAssistant;
use App\Livewire\CrmChat;
use App\Mcp\Resources\CrmExplorerApp;
use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\GetCrmRecord;
use App\Mcp\Tools\SearchCrm;
use App\Models\AiSetting;
use App\Models\Company;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Ai\Responses\Data\ToolCall;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['app.env' => 'local']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function createSessionCrmApp(User $user): string
{
    $id = 'test-session-app';
    session()->put('crm_chat.'.$user->id, [[
        'role' => 'assistant', 'content' => 'Your CRM records',
        'apps' => [['id' => $id, 'name' => 'search-crm', 'arguments' => ['type' => 'company'],
            'data' => ['records' => [], 'pagination' => ['page' => 1, 'last_page' => 1, 'total' => 0]]]],
    ]]);

    return $id;
}

test('CRM tools advertise the MCP app and the server serves a self contained app resource', function () {
    $resource = new CrmExplorerApp;
    foreach ([new SearchCrm, new GetCrmRecord] as $tool) {
        expect($tool->toArray()['_meta']['ui']['resourceUri'])->toBe($resource->uri());
        expect($tool->toArray()['_meta']['ui']['visibility'])->toBe(['model', 'app']);
    }
    expect($resource->mimeType())->toBe('text/html;profile=mcp-app');
    CrmServer::resources()->assertRegistered([CrmExplorerApp::class]);
    CrmServer::resource(CrmExplorerApp::class)->assertOk()->assertSee('CRM Explorer')
        ->assertSee('createMcpApp')->assertSee('callServerTool')->assertSee('onToolResult');
});

test('interactive search filters status sorts and paginates all matching active records', function () {
    $first = Task::factory()->create(['title' => 'Follow up one', 'status' => 'pending']);
    $second = Task::factory()->create(['title' => 'Follow up two', 'status' => 'pending']);
    Task::factory()->completed()->create(['title' => 'Follow up completed']);
    Task::factory()->trashed()->create(['title' => 'Follow up deleted', 'status' => 'pending']);

    CrmServer::tool(SearchCrm::class, ['type' => 'task', 'text' => 'Follow up', 'status' => 'pending',
        'sort' => 'id', 'direction' => 'desc', 'page' => 1, 'per_page' => 1])
        ->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('records.0.id', $second->id)->where('pagination.total', 2)->where('pagination.last_page', 2)->etc());
    CrmServer::tool(SearchCrm::class, ['type' => 'task', 'status' => 'pending', 'sort' => 'id',
        'direction' => 'desc', 'page' => 2, 'per_page' => 1])
        ->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json->where('records.0.id', $first->id)->etc());
    CrmServer::tool(SearchCrm::class, ['type' => 'task', 'sort' => 'id desc; drop table tasks'])->assertHasErrors();
    CrmServer::tool(SearchCrm::class, ['type' => 'task', 'status' => 'unknown'])->assertHasErrors();
});

test('chat tool results create session bound interactive frames and cost one credit', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $company = Company::factory()->create(['name' => 'Full company title']);
    $settings = new AiSetting(['provider' => 'openai', 'model' => 'test-model', 'api_key' => 'test-only-key']);
    $settings->id = 1;
    $settings->save();
    CrmAssistant::fake([new ToolCall('search', 'search-crm', ['type' => 'company']), 'Found your companies.'])
        ->preventStrayPrompts();

    $chat = Livewire::test(CrmChat::class)->set('draft', 'Show companies')->call('send')
        ->assertHasNoErrors()->assertSeeHtml('sandbox="allow-scripts"')->assertSee('Interactive CRM explorer');
    $app = session('crm_chat.'.$user->id)[1]['apps'][0];
    expect($app['data']['records'][0]['id'])->toBe($company->id);
    expect($chat->snapshot['data'])->not->toHaveKey('apps');
    $chat->call('appContext', $app['id'])->assertReturned($app);
    expect($user->fresh()->ai_credits_used)->toBe(1);
});

test('app document requires panel authentication and enforces sandbox CSP', function () {
    $this->get(route('crm.app'))->assertRedirect(Filament::getLoginUrl());
    $this->actingAs(User::factory()->create());
    $this->get(route('crm.app'))->assertOk()->assertSee('CRM Explorer')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; connect-src 'none'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");
    Gate::define('use-ai-assistant', fn (User $user): bool => false);
    $this->get(route('crm.app'))->assertForbidden();
});

test('app tool calls remain available without credits and do not change the balance', function () {
    $user = User::factory()->create(['ai_credit_month' => User::currentAiCreditMonth(), 'ai_credits_used' => 30]);
    $this->actingAs($user);
    $id = createSessionCrmApp($user);
    $company = Company::factory()->create(['name' => '<script>Untrusted record</script>']);
    CrmAssistant::fake()->preventStrayPrompts();

    $chat = Livewire::test(CrmChat::class);
    $chat->call('callAppTool', $id, 'search-crm', ['type' => 'company'])->assertHasNoErrors();
    expect($chat->effects['returns'][0]['structuredContent']['records'][0]['name'])->toBe('<script>Untrusted record</script>');
    $chat->call('callAppTool', $id, 'get-crm-record', ['type' => 'company', 'id' => $company->id])->assertHasNoErrors();
    expect($chat->effects['returns'][0]['structuredContent']['record']['id'])->toBe($company->id);
    expect($user->fresh()->ai_credits_used)->toBe(30);
    CrmAssistant::assertNeverPrompted();
});

test('app bridge refuses writes unknown tools and other users session apps', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $id = createSessionCrmApp($user);
    $company = Company::factory()->create(['name' => 'Unchanged']);
    Livewire::test(CrmChat::class)->call('callAppTool', $id, 'update-company', ['id' => $company->id, 'name' => 'Changed'])
        ->assertForbidden();
    expect($company->fresh()->name)->toBe('Unchanged');
    $this->actingAs(User::factory()->create());
    Livewire::test(CrmChat::class)->call('appContext', $id)->assertNotFound();
    Livewire::test(CrmChat::class)->call('callAppTool', $id, 'search-crm', ['type' => 'company'])->assertNotFound();
});

test('app callbacks handle validation deleted records and request limits', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $id = createSessionCrmApp($user);
    $company = Company::factory()->trashed()->create();
    $chat = Livewire::test(CrmChat::class)
        ->call('callAppTool', $id, 'get-crm-record', ['type' => 'company', 'id' => $company->id]);
    expect($chat->effects['returns'][0]['isError'])->toBeTrue();
    $chat->call('callAppTool', $id, 'search-crm', ['type' => 'invalid']);
    expect($chat->effects['returns'][0]['isError'])->toBeTrue();
    for ($index = 0; $index < 60; $index++) {
        RateLimiter::hit('crm-app-tools:'.$user->id, 60);
    }
    $chat->call('callAppTool', $id, 'search-crm', ['type' => 'company']);
    expect($chat->effects['returns'][0]['content'][0]['text'])->toContain('Too many requests');
    $chat->call('clearChat')->call('appContext', $id)->assertNotFound();
});
