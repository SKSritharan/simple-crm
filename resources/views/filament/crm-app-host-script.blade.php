@once
<script>
window.crmAppHost = (wire, appId, url) => ({
    error: '',
    initialized: false,
    pending: 0,
    init() {
        this.receive = async (event) => {
            if (event.source !== this.$refs.frame.contentWindow || event.origin !== 'null') return;
            const message = event.data;
            if (!message || message.jsonrpc !== '2.0' || typeof message.method !== 'string') return;
            const reply = (result) => this.post({ id: message.id, result });
            const fail = (text, code = -32601) => this.post({ id: message.id, error: { code, message: text } });
            try {
                if (message.method === 'ui/initialize') {
                    if (message.params?.protocolVersion !== '2026-01-26') return fail('Unsupported app protocol');
                    reply({ protocolVersion: '2026-01-26', hostInfo: { name: 'Filament CRM', version: '1.0.0' },
                        hostCapabilities: { serverTools: {} }, hostContext: this.context() });
                } else if (message.method === 'ui/notifications/initialized' && !this.initialized) {
                    this.initialized = true;
                    clearTimeout(this.connectionTimer);
                    const app = await wire.appContext(appId);
                    this.post({ method: 'ui/notifications/tool-input', params: { arguments: app.arguments } });
                    this.post({ method: 'ui/notifications/tool-result', params: { structuredContent: app.data,
                        content: [{ type: 'text', text: JSON.stringify(app.data) }] } });
                } else if (message.method === 'tools/call' && this.initialized && message.id !== undefined) {
                    if (!['search-crm', 'get-crm-record'].includes(message.params?.name)) return fail('Tool not permitted', -32602);
                    if (this.pending >= 3) return fail('Too many pending requests', -32000);
                    this.pending++;
                    try {
                        reply(await wire.callAppTool(appId, message.params.name, message.params.arguments ?? {}));
                    } finally { this.pending--; }
                } else if (message.method === 'ping') {
                    reply({});
                } else if (message.method === 'ui/notifications/size-changed' && this.initialized) {
                    const height = Number(message.params?.height);
                    if (Number.isFinite(height)) this.$refs.frame.style.height = Math.min(640, Math.max(240, height)) + 'px';
                } else if (message.id !== undefined) {
                    fail('This host does not support that capability');
                }
            } catch {
                this.error = 'The interactive view is unavailable. Reopen the chat or ask again.';
                if (message.id !== undefined) fail('App session unavailable', -32000);
            }
        };
        window.addEventListener('message', this.receive);
        this.themeObserver = new MutationObserver(() => {
            if (this.initialized) this.post({ method: 'ui/notifications/host-context-changed', params: this.context() });
        });
        this.themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        this.$refs.frame.src = url;
        this.connectionTimer = setTimeout(() => {
            if (!this.initialized) this.error = 'The interactive view could not connect. Reload the page to try again.';
        }, 15000);
    },
    context() {
        return { theme: document.documentElement.classList.contains('dark') ? 'dark' : 'light', displayMode: 'inline',
            availableDisplayModes: ['inline'], locale: document.documentElement.lang || 'en' };
    },
    post(message) {
        this.$refs.frame.contentWindow?.postMessage({ jsonrpc: '2.0', ...message }, '*');
    },
    destroy() {
        this.post({ id: 'teardown-' + appId, method: 'ui/resource-teardown', params: {} });
        window.removeEventListener('message', this.receive);
        this.themeObserver?.disconnect();
        clearTimeout(this.connectionTimer);
    },
});
</script>
@endonce
