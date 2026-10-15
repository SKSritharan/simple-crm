<div x-data="{ chatOpen: false }">
    <button type="button" class="crm-chat-launcher" popovertarget="crm-assistant" popovertargetaction="toggle"
        x-ref="launcher" aria-label="AI Assistant" aria-controls="crm-assistant" x-bind:aria-expanded="chatOpen.toString()">
        <span class="crm-chat-launcher-label" aria-hidden="true">AI Assistant</span>
        <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedSparkles" class="size-6 shrink-0" />
    </button>

    <section id="crm-assistant" popover="auto" wire:ignore.self class="crm-chat-panel" role="dialog"
        aria-labelledby="crm-chat-title" aria-describedby="crm-chat-description"
        x-on:toggle="chatOpen = $event.newState === 'open'">
        <header class="crm-chat-header">
            <div>
                <h2 id="crm-chat-title" class="text-base font-semibold text-gray-950 dark:text-white">CRM Assistant</h2>
                <p id="crm-chat-description" class="mt-1 text-sm text-gray-500 dark:text-gray-400">Ask about your CRM. Read-only assistance.</p>
            </div>
            <x-filament::icon-button icon="heroicon-o-x-mark" label="Close chat" color="gray"
                popovertarget="crm-assistant" popovertargetaction="hide" />
        </header>

        <div class="crm-chat-body">
            <div class="text-sm text-gray-600 dark:text-gray-400" aria-live="polite" wire:poll.60s.visible>
                <p>{{ $remainingCredits }} of 30 credits remaining</p>
                <p>Renews {{ $renewalDate }} at midnight (Asia/Colombo).</p>
                @if ($remainingCredits === 0)
                    <p>You have used all your AI credits for this month. You can send messages again when your credits renew.</p>
                @endif
            </div>
            @if (! $configured)
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    <a class="text-primary-600 underline dark:text-primary-400" href="{{ \App\Filament\Pages\AiSettings::getUrl() }}">Configure AI Settings</a> to start chatting.
                </p>
            @endif

            <div class="space-y-4" role="log" aria-label="Chat messages" aria-live="polite"
                x-data x-on:crm-chat-updated.window="$nextTick(() => $el.parentElement.scrollTop = $el.parentElement.scrollHeight)">
                @forelse ($messages as $message)
                    <div wire:key="message-{{ $loop->index }}" @class([
                        'w-[90%] rounded-xl p-4 text-sm break-words',
                        'ms-auto bg-primary-50 text-gray-950 dark:bg-primary-500/10 dark:text-white' => $message['role'] === 'user',
                        'me-auto bg-gray-50 text-gray-950 dark:bg-white/5 dark:text-white' => $message['role'] === 'assistant',
                    ])>
                        <span class="mb-2 block text-xs font-semibold">{{ $message['role'] === 'user' ? 'You' : 'Assistant' }}</span>
                        @if ($message['role'] === 'assistant')
                            <div class="crm-chat-markdown">{!! \Illuminate\Support\Str::markdown($message['content'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
                            @foreach ($message['apps'] ?? [] as $app)
                                <div wire:key="crm-app-{{ $app['id'] }}" wire:ignore class="mt-3"
                                    x-data="crmAppHost($wire, @js($app['id']), @js(route('crm.app')))">
                                    <p x-show="error" x-text="error" role="alert" class="text-sm text-danger-600 dark:text-danger-400"></p>
                                    <iframe x-ref="frame" title="Interactive CRM explorer" sandbox="allow-scripts"
                                        referrerpolicy="no-referrer" class="w-full rounded-lg border-0" style="height: 420px"></iframe>
                                </div>
                            @endforeach
                        @else
                            <div class="whitespace-pre-wrap">{{ $message['content'] }}</div>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">Try “Find my open deals” or “Which tasks need attention?” Your chat is temporary and stays in this browser session.</p>
                @endforelse
            </div>
        </div>

        <footer class="crm-chat-composer">
            <p wire:loading wire:target="send" role="status" class="text-sm text-gray-500 dark:text-gray-400">Looking into your CRM…</p>

            <form wire:submit="send" class="space-y-3">
                <label for="crm-chat-draft" class="text-sm font-medium text-gray-950 dark:text-white">Message</label>
                <textarea id="crm-chat-draft" wire:model="draft" maxlength="4000" rows="3"
                    placeholder="Ask a question about your CRM"
                    class="w-full rounded-lg border border-gray-300 bg-white p-3 text-sm text-gray-950 dark:border-white/20 dark:bg-gray-900 dark:text-white"
                    wire:loading.attr="disabled" wire:target="send,clearChat"
                    x-on:keydown.ctrl.enter.prevent="$wire.send()" x-on:keydown.meta.enter.prevent="$wire.send()"></textarea>
                @error('draft') <p role="alert" class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p> @enderror
                <div class="flex items-center justify-between gap-3">
                    <x-filament::button type="button" color="gray" wire:click="clearChat" wire:loading.attr="disabled" wire:target="send,clearChat">Clear chat</x-filament::button>
                    <x-filament::button type="submit" :disabled="! $configured || $remainingCredits === 0" wire:loading.attr="disabled" wire:target="send,clearChat">Send</x-filament::button>
                </div>
            </form>
        </footer>
    </section>
</div>
