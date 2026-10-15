<?php

namespace App\Livewire;

use App\Ai\Agents\CrmAssistant;
use App\Mcp\Tools\GetCrmRecord;
use App\Mcp\Tools\SearchCrm;
use App\Models\AiSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Ai;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\ResponseFactory;
use Livewire\Component;
use RuntimeException;
use Throwable;

class CrmChat extends Component
{
    public string $draft = '';

    public function boot(): void
    {
        Gate::authorize('use-ai-assistant');
    }

    public function send(): void
    {
        Gate::authorize('use-ai-assistant');
        $this->draft = trim($this->draft);
        $this->validate(['draft' => ['required', 'string', 'max:4000']]);

        $lock = Cache::lock('crm-chat:user:'.auth()->id(), 1200);
        if (! $lock->get()) {
            $this->addError('draft', 'A response is already in progress. Please wait.');

            return;
        }

        try {
            $creditMonth = User::currentAiCreditMonth();
            $user = User::findOrFail(auth()->id());
            $remainingCredits = $user->remainingAiCredits($creditMonth);
            if ($remainingCredits === 0) {
                $this->addError('draft', 'You have used all 30 AI credits this month. Your credits renew on the first of next month at midnight Asia/Colombo.');

                return;
            }

            $settings = AiSetting::shared();
            if (! $settings?->isConfigured()) {
                $this->addError('draft', 'Configure the assistant in AI Settings first.');

                return;
            }

            $rateKey = 'crm-chat:'.auth()->id();
            if (RateLimiter::tooManyAttempts($rateKey, 10)) {
                $this->addError('draft', 'You have sent too many messages. Try again in a minute.');

                return;
            }
            RateLimiter::hit($rateKey, 60);

            $provider = Ai::build([
                'name' => 'crm-assistant',
                'driver' => $settings->provider,
                'key' => $settings->api_key,
            ]);
            $history = session()->get($this->historyKey(), []);
            $response = (new CrmAssistant)->withMessages(array_map(
                fn (array $message): Message => new Message($message['role'], $message['content']),
                array_slice($history, -20),
            ))->prompt($this->draft, provider: $provider->name(), model: $settings->model, timeout: 120);

            if (blank($response->text)) {
                throw new RuntimeException('The provider returned an empty reply.');
            }

            $user->ai_credit_month = $creditMonth;
            $user->ai_credits_used = User::MONTHLY_AI_CREDITS - $remainingCredits + 1;
            $user->saveOrFail();

            $history[] = ['role' => 'user', 'content' => $this->draft];
            $apps = $response->toolResults
                ->filter(fn (ToolResult $result): bool => ! $result->failed && ! $result->denied
                    && in_array($result->name, ['search-crm', 'get-crm-record'], true))
                ->map(function (ToolResult $result): array {
                    $data = is_string($result->result) ? json_decode($result->result, true) : $result->result;

                    return ['id' => (string) Str::uuid(), 'name' => $result->name,
                        'arguments' => $result->arguments, 'data' => $data];
                })->filter(fn (array $app): bool => is_array($app['data'])
                    && (isset($app['data']['records']) || isset($app['data']['record'])))
                ->reverse()->unique(fn (array $app): string => $app['name'].':'.($app['arguments']['type'] ?? ''))
                ->take(3)->reverse()->values()->all();
            $history[] = ['role' => 'assistant', 'content' => $response->text, 'apps' => $apps];
            session()->put($this->historyKey(), array_slice($history, -20));
            $this->draft = '';
            $this->resetValidation();
            $this->dispatch('crm-chat-updated');
        } catch (Throwable) {
            $this->addError('draft', 'The AI provider could not respond. Check the API key and model in AI Settings, or try again shortly.');
        } finally {
            $lock->release();
        }
    }

    public function clearChat(): void
    {
        Gate::authorize('use-ai-assistant');
        $lock = Cache::lock('crm-chat:user:'.auth()->id(), 1200);
        if (! $lock->get()) {
            $this->addError('draft', 'Wait for the current response before clearing the chat.');

            return;
        }
        try {
            session()->forget($this->historyKey());
            $this->draft = '';
            $this->resetValidation();
        } finally {
            $lock->release();
        }
    }

    private function historyKey(): string
    {
        return 'crm_chat.'.auth()->id();
    }

    /** @return array<string, mixed> */
    public function appContext(string $appId): array
    {
        Gate::authorize('use-ai-assistant');
        foreach (session()->get($this->historyKey(), []) as $message) {
            foreach ($message['apps'] ?? [] as $app) {
                if ($app['id'] === $appId) {
                    return $app;
                }
            }
        }
        abort(404);
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function callAppTool(string $appId, string $name, array $arguments): array
    {
        $this->appContext($appId);
        $tool = match ($name) {
            'search-crm' => new SearchCrm,
            'get-crm-record' => new GetCrmRecord,
            default => null,
        };
        abort_if($tool === null, 403);
        $rateKey = 'crm-app-tools:'.auth()->id();
        if (RateLimiter::tooManyAttempts($rateKey, 60)) {
            return ['isError' => true, 'content' => [['type' => 'text', 'text' => 'Too many requests. Try again in a minute.']]];
        }
        RateLimiter::hit($rateKey, 60);

        try {
            $response = $tool->handle(new McpRequest($arguments));
            if ($response instanceof ResponseFactory) {
                $data = $response->getStructuredContent();

                return ['structuredContent' => $data, 'content' => [['type' => 'text', 'text' => json_encode($data)]]];
            }

            return ['isError' => true, 'content' => [['type' => 'text', 'text' => 'Record not found. It may have been deleted.']]];
        } catch (ValidationException) {
            return ['isError' => true, 'content' => [['type' => 'text', 'text' => 'Invalid CRM filters or record ID.']]];
        }
    }

    public function render(): View
    {
        return view('livewire.crm-chat', [
            'messages' => session()->get($this->historyKey(), []),
            'configured' => AiSetting::shared() !== null,
            'remainingCredits' => User::findOrFail(auth()->id())->remainingAiCredits(),
            'renewalDate' => CarbonImmutable::now(User::AI_CREDIT_TIMEZONE)->startOfMonth()->addMonth()->format('F j, Y'),
        ]);
    }
}
