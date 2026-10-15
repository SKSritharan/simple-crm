<x-filament-panels::page>
    <x-filament::section heading="Shared AI configuration">
        <p class="text-sm text-gray-600 dark:text-gray-400">These settings apply to everyone in the admin panel. All panel users can update them.</p>
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ $hasConfiguredKey ? 'An API key is configured. The saved key is never displayed.' : 'No API key is configured yet.' }}</p>
        <form wire:submit="save" class="mt-6 space-y-6">
            {{ $this->form }}
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">Save settings</x-filament::button>
        </form>
    </x-filament::section>
</x-filament-panels::page>
