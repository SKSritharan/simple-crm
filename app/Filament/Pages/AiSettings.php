<?php

namespace App\Filament\Pages;

use App\Models\AiSetting;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class AiSettings extends Page
{
    protected static ?string $title = 'AI Settings';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 100;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected string $view = 'filament.pages.ai-settings';

    /** @var array{provider?: string, model?: string, api_key?: string}|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Gate::allows('use-ai-assistant');
    }

    public function mount(): void
    {
        Gate::authorize('use-ai-assistant');
        $settings = AiSetting::shared();
        $this->form->fill([
            'provider' => $settings?->provider ?? 'openai',
            'model' => $settings?->model ?? '',
            'api_key' => '',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('provider')->options([
                'openai' => 'OpenAI', 'anthropic' => 'Anthropic Claude', 'gemini' => 'Google Gemini',
                'openrouter' => 'OpenRouter',
            ])->required()->in(['openai', 'anthropic', 'gemini', 'openrouter']),
            TextInput::make('model')->label('Model name')->required()->maxLength(255)
                ->helperText('Enter a text model available to your provider account that supports tools.'),
            TextInput::make('api_key')->label('API key')->password()->autocomplete('new-password')
                ->maxLength(4096)->helperText('Leave blank to keep the saved key. Changing provider requires a replacement key.'),
        ])->statePath('data');
    }

    public function save(): void
    {
        Gate::authorize('use-ai-assistant');
        $data = $this->form->getState();
        $settings = AiSetting::shared() ?? new AiSetting;

        if (blank($data['api_key'] ?? null) && (! $settings->exists || $settings->provider !== $data['provider'])) {
            throw ValidationException::withMessages(['data.api_key' => 'Enter an API key for this provider.']);
        }

        $settings->id = 1;
        $settings->provider = $data['provider'];
        $settings->model = trim($data['model']);
        if (filled($data['api_key'] ?? null)) {
            $settings->api_key = trim($data['api_key']);
        }
        $settings->save();
        $this->data['api_key'] = '';
        Notification::make()->title('AI settings saved')->success()->send();
    }

    /** @return array{hasConfiguredKey: bool} */
    protected function getViewData(): array
    {
        return ['hasConfiguredKey' => AiSetting::shared() !== null];
    }
}
