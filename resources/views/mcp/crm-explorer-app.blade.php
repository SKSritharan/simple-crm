<x-mcp::app title="CRM Explorer">
    <x-slot:head>
        <style>
            :root { color-scheme: light dark; --bg: #fff; --text: #18181b; --muted: #52525b; --border: #d4d4d8; --accent: #b45309; }
            :root[data-theme="dark"] { --bg: #18181b; --text: #fafafa; --muted: #a1a1aa; --border: #3f3f46; --accent: #fbbf24; }
            * { box-sizing: border-box; }
            body { margin: 0; padding: 12px; background: var(--color-background-primary, var(--bg)); color: var(--color-text-primary, var(--text)); font: 14px/1.5 system-ui, sans-serif; overflow-wrap: anywhere; }
            h1 { font-size: 16px; margin: 0 0 12px; }
            form, .pagination { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 12px 0; }
            input, select, button { font: inherit; border: 1px solid var(--border); border-radius: 6px; padding: 7px 10px; background: var(--bg); color: var(--text); max-width: 100%; }
            input { flex: 1; min-width: 100px; }
            button { cursor: pointer; }
            button:disabled { opacity: .5; cursor: default; }
            button:focus-visible, input:focus-visible, select:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
            .scroll { overflow-x: auto; max-width: 100%; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border-bottom: 1px solid var(--border); padding: 8px; text-align: start; vertical-align: top; }
            th { color: var(--muted); font-size: 12px; }
            td button { text-align: start; border: 0; padding: 0; color: var(--accent); text-decoration: underline; }
            #status { color: var(--muted); }
            #details { margin-top: 16px; }
            #details dt { color: var(--muted); font-size: 12px; }
            #details dd { margin: 0 0 8px; white-space: pre-wrap; }
            [hidden] { display: none !important; }
        </style>
        <script type="module">
            createMcpApp(async (app) => {
                const form = document.querySelector('form');
                const status = document.getElementById('status');
                const body = document.querySelector('tbody');
                const details = document.getElementById('details');
                let query = { type: 'task', page: 1, per_page: 10 };
                let pagination = { page: 1, last_page: 1 };
                let busy = false;
                let generation = 0;

                const text = (value) => value === null || value === undefined ? '—' : String(value);
                const unpack = (result) => {
                    if (result.isError) throw new Error(result.content?.[0]?.text || 'Unable to load CRM records.');
                    if (result.structuredContent) return result.structuredContent;
                    return JSON.parse(result.content?.find(item => item.type === 'text')?.text || '{}');
                };
                const setBusy = (value) => {
                    busy = value;
                    form.querySelectorAll('button, input, select').forEach(el => el.disabled = value);
                    document.getElementById('previous').disabled = value || pagination.page <= 1;
                    document.getElementById('next').disabled = value || pagination.page >= pagination.last_page;
                    body.querySelectorAll('button').forEach(el => el.disabled = value);
                    status.setAttribute('aria-busy', String(value));
                };
                const showRecord = (record) => {
                    details.replaceChildren();
                    const heading = document.createElement('h1');
                    heading.textContent = 'Record details';
                    const list = document.createElement('dl');
                    for (const [key, value] of Object.entries(record)) {
                        const term = document.createElement('dt');
                        term.textContent = key.replaceAll('_', ' ');
                        const description = document.createElement('dd');
                        description.textContent = text(value);
                        list.append(term, description);
                    }
                    details.append(heading, list);
                    details.hidden = false;
                };
                const detail = async (record) => {
                    if (busy) return;
                    setBusy(true);
                    try {
                        const result = unpack(await app.callServerTool('get-crm-record', { type: record.type, id: record.id }));
                        showRecord(result.record);
                        status.textContent = 'Record details loaded.';
                    } catch (error) { status.textContent = error.message; }
                    finally { setBusy(false); app.resize(); }
                };
                const syncForm = () => {
                    form.elements.type.value = query.type || 'task';
                    form.elements.text.value = query.text || '';
                    form.elements.sort.value = (query.sort || 'id') + ':' + (query.direction || 'asc');
                    const filter = form.elements.filter;
                    filter.replaceChildren(new Option('All statuses', ''));
                    const values = query.type === 'task' ? ['pending', 'completed', 'cancelled']
                        : query.type === 'deal' ? ['new', 'qualified', 'proposal', 'negotiation', 'won', 'lost'] : [];
                    values.forEach(value => filter.add(new Option(value, value)));
                    filter.value = query.status || query.stage || '';
                    filter.hidden = values.length === 0;
                };
                const render = (data) => {
                    if (data.record) {
                        query = { type: data.record.type, page: 1, per_page: 10 };
                        showRecord(data.record);
                        syncForm();
                        status.textContent = 'Record details loaded. Search to browse related records.';
                        app.resize();
                        return;
                    }
                    query = { ...query, ...(data.query || {}) };
                    pagination = data.pagination || { page: 1, last_page: 1, total: 0 };
                    syncForm();
                    body.replaceChildren();
                    details.hidden = true;
                    for (const record of data.records || []) {
                        const row = document.createElement('tr');
                        const id = document.createElement('td');
                        id.textContent = record.id;
                        const name = document.createElement('td');
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.textContent = record.name || record.title || [record.first_name, record.last_name].filter(Boolean).join(' ') || record.body || '#' + record.id;
                        button.addEventListener('click', () => detail(record));
                        name.append(button);
                        const state = document.createElement('td');
                        state.textContent = record.status || record.stage || record.email || '—';
                        row.append(id, name, state);
                        body.append(row);
                    }
                    status.textContent = pagination.total === 0 ? 'No matching records.'
                        : pagination.total + ' records · Page ' + pagination.page + ' of ' + pagination.last_page;
                    setBusy(false);
                    app.resize();
                };
                const load = async (page = 1) => {
                    if (busy) return;
                    const token = ++generation;
                    const [sort, direction] = form.elements.sort.value.split(':');
                    query = { type: form.elements.type.value, page, per_page: 10, sort, direction };
                    if (form.elements.text.value.trim()) query.text = form.elements.text.value.trim();
                    if (form.elements.filter.value && query.type === 'task') query.status = form.elements.filter.value;
                    if (form.elements.filter.value && query.type === 'deal') query.stage = form.elements.filter.value;
                    setBusy(true);
                    status.textContent = 'Loading CRM records…';
                    try {
                        const result = unpack(await app.callServerTool('search-crm', query));
                        if (token === generation) render(result);
                    } catch (error) { status.textContent = error.message; }
                    finally { setBusy(false); app.resize(); }
                };
                form.addEventListener('submit', event => { event.preventDefault(); load(); });
                document.getElementById('search').addEventListener('click', () => load());
                form.addEventListener('keydown', event => {
                    if (event.key === 'Enter' && event.target instanceof HTMLInputElement) {
                        event.preventDefault();
                        load();
                    }
                });
                form.elements.type.addEventListener('change', () => {
                    query = { type: form.elements.type.value, page: 1, per_page: 10 };
                    syncForm();
                    load();
                });
                document.getElementById('previous').addEventListener('click', () => load(pagination.page - 1));
                document.getElementById('next').addEventListener('click', () => load(pagination.page + 1));
                app.onToolInput(({ arguments: args }) => { query = { ...query, ...args }; syncForm(); });
                app.onToolResult(result => {
                    try { render(unpack(result)); } catch (error) { status.textContent = error.message; }
                });
                app.onToolCancelled(() => { status.textContent = 'The tool call was cancelled.'; setBusy(false); });
                app.autoResize();
                syncForm();
                setBusy(false);
            }).catch(() => { document.getElementById('status').textContent = 'This view requires a compatible MCP Apps host.'; });
        </script>
    </x-slot:head>

    <h1>CRM Explorer</h1>
    <form aria-label="CRM search">
        <select name="type" aria-label="Record type">
            <option value="company">Companies</option>
            <option value="contact">Contacts</option>
            <option value="deal">Deals</option>
            <option value="task">Tasks</option>
            <option value="note">Notes</option>
        </select>
        <input name="text" aria-label="Search records" placeholder="Search CRM…" maxlength="255">
        <select name="filter" aria-label="Status filter"></select>
        <select name="sort" aria-label="Sort records">
            <option value="id:asc">ID ascending</option>
            <option value="id:desc">ID descending</option>
            <option value="created_at:desc">Newest first</option>
            <option value="created_at:asc">Oldest first</option>
        </select>
        <button id="search" type="button">Search</button>
    </form>
    <p id="status" role="status" aria-live="polite">Connecting to the CRM host…</p>
    <div class="scroll">
        <table aria-label="CRM records">
            <thead><tr><th>ID</th><th>Name / title</th><th>Status / email</th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
    <div class="pagination">
        <button id="previous" type="button" disabled>Previous</button>
        <button id="next" type="button" disabled>Next</button>
    </div>
    <section id="details" aria-label="Record details" hidden></section>
</x-mcp::app>
