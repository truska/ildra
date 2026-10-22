/* Shared admin table navigation; only filter/sort preferences use tab-local storage. */
(() => {
    'use strict';
    const main = document.querySelector('main.admin-content');
    const configNode = document.getElementById('admin-table-state');
    if (!main || !configNode) return;
    const config = JSON.parse(configNode.textContent);
    const here = new URL(window.location.href);
    const editor = /(?:_edit|entry_item|email_view)\.php$/.test(here.pathname) || ['edit', 'id', 'edit_winner', 'mode', 'new'].some(key => here.searchParams.has(key))
        || /(?:edit|new)$/.test(here.searchParams.get('view') || '');
    const hasServerFilters = !!main.querySelector('.admin-table-filter-row');
    const token = config.incoming && (editor || (config.origin?.path !== here.pathname && !hasServerFilters))
        ? config.incoming : (!editor || hasServerFilters ? config.candidate : '');
    const context = !token ? null : (token === config.incoming ? config.origin : {path: here.pathname, query: config.query});
    const localAdminUrl = value => {
        try {
            const url = new URL(value, here);
            return url.origin === here.origin && url.pathname.startsWith(here.pathname.slice(0, here.pathname.lastIndexOf('/') + 1)) ? url : null;
        } catch (_) { return null; }
    };
    const hidden = (form, name, value) => {
        if (Array.from(form.elements).some(control => control.name === name)) return;
        const input = document.createElement('input');
        input.type = 'hidden'; input.name = name; input.value = value;
        form.appendChild(input);
    };
    const isClear = link => link.hasAttribute('data-table-clear') || /^clear(?:\s+(?:all|filters))?$/i.test(link.textContent.trim());
    const decorateLink = link => {
        if (!link.getAttribute('href') || link.getAttribute('href').startsWith('#') || link.hasAttribute('download')) return;
        const url = localAdminUrl(link.href);
        if (!url) return;
        if (isClear(link)) {
            url.searchParams.delete('_table_context');
            url.searchParams.set('_table_clear', '1');
        } else if (token) {
            if (context && url.pathname === context.path && !url.searchParams.has('edit') && !url.searchParams.has('id')) {
                Object.entries(context.query).forEach(([key, value]) => {
                    if (!url.searchParams.has(key)) url.searchParams.set(key, value);
                });
            }
            url.searchParams.set('_table_context', token);
        }
        link.href = url.href;
    };
    main.querySelectorAll('a[href]').forEach(decorateLink);
    // Also covers links created by modal and inline-edit scripts after initial rendering.
    main.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (link) decorateLink(link);
    }, true);

    const prepareForm = form => {
        if ((form.getAttribute('method') || 'get').toLowerCase() === 'post') {
            if (token && localAdminUrl(form.getAttribute('action') || here.href)) hidden(form, '_table_context', token);
            return;
        }
        const headerControls = [...main.querySelectorAll('.admin-table-filter-row [name]')].filter(control => control.form === form);
        if (!headerControls.length && !/filter|search/i.test(form.id)) return;
        // Header submissions retain sorting and the chosen page size, restarting at page one.
        ['sort', 'dir', 'per_page', 'entry_sort', 'entry_dir', 'tx_sort', 'tx_dir', 'event_sort', 'event_dir'].forEach(key => {
            const value = here.searchParams.get(key) ?? config.query[key];
            if (value !== undefined && value !== null) hidden(form, key, value);
        });
        headerControls.forEach(control => {
            if (!control.getClientRects().length && headerControls.some(other => other !== control && other.name === control.name && other.getClientRects().length)) control.disabled = true;
        });
        // A page can contain independently filtered tables (for example Finance).
        const otherNames = new Set([...main.querySelectorAll('.admin-table-filter-row [name]')].filter(control => control.form !== form).map(control => control.name));
        Object.entries(config.query).forEach(([key, value]) => {if (otherNames.has(key)) hidden(form, key, value);});
        hidden(form, '_table_filter', '1');
        // Preserve only route selectors, never reinsert cleared filter inputs.
        ['view', 'tab', 'page_id', 'event_id', 'horse_id', 'news_id', 'element_id', 'award_id', 'winners_for', 'folder'].forEach(key => {
            if (here.searchParams.has(key)) hidden(form, key, here.searchParams.get(key));
        });
    };
    main.querySelectorAll('form').forEach(prepareForm);
    main.addEventListener('submit', event => prepareForm(event.target), true);

    // Legacy tables receive the same header UI while retaining their existing
    // server queries, reports and inline forms. Filtering operates on loaded rows.
    const storage = {
        get(key) { try { return JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (_) { return null; } },
        set(key, value) { try { sessionStorage.setItem(key, JSON.stringify(value)); } catch (_) {} },
    };
    main.querySelectorAll('table').forEach((table, tableIndex) => {
        if (table.hasAttribute('data-admin-table-static') || table.querySelector('.admin-table-filter-row') || !table.tHead || !table.tBodies.length) return;
        const headers = [...table.tHead.rows[0].cells];
        const body = table.tBodies[0];
        const rows = [...body.rows].filter(row => row.cells.length === headers.length && [...row.cells].every(cell => cell.colSpan === 1 && cell.rowSpan === 1));
        if (headers.some(cell => cell.colSpan > 1 || cell.rowSpan > 1)) return;
        const scope = new URLSearchParams();
        ['view', 'tab', 'page_id', 'event_id', 'horse_id', 'news_id', 'element_id', 'award_id', 'winners_for', 'folder'].forEach(key => {
            if (here.searchParams.has(key)) scope.set(key, here.searchParams.get(key));
        });
        const key = `ildra-admin-table:${config.userId}:${here.pathname}?${scope}:${table.id || tableIndex}`;
        const signature = headers.map(header => header.textContent.trim()).join('|');
        const saved = storage.get(key);
        const state = saved?.signature === signature ? saved : {signature, filters: {}, sort: null, dir: 'asc', page: 1, perPage: 50};
        const initialRows = [...body.rows];
        const originalCells = new Map(rows.map(row => [row, [...row.cells]]));
        const originalEmpty = initialRows.filter(row => !rows.includes(row));
        if (originalEmpty.some(row => !/no |not found|nothing|unavailable/i.test(row.textContent))) {filterRow.remove(); return;}
        table.dataset.adminTableEnhanced = '1';
        const text = (row, index) => {
            const cell = originalCells.get(row)[index];
            const fields = [...cell.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]), textarea, select')];
            return (fields.length ? fields.map(field => field.tagName === 'SELECT' ? field.selectedOptions[0]?.textContent || '' : field.value).join(' ') : cell.textContent).trim().replace(/\s+/g, ' ');
        };
        const filterRow = table.tHead.insertRow();
        filterRow.className = 'admin-table-filter-row';
        const filterControls = new Map();
        const sortButtons = new Map();
        const collator = new Intl.Collator(undefined, {numeric: true, sensitivity: 'base'});
        const eligible = headers.map((header, index) => !!header.textContent.trim()
            && !/^(?:actions?|quick access|select|image|preview)$/i.test(header.textContent.trim())
            && (!rows.length || !rows.every(row => row.cells[index].querySelector('button, input[type=checkbox], input[type=radio]'))));
        if (!eligible.some(Boolean)) { filterRow.remove(); return; }
        const controls = document.createElement('div');
        controls.className = 'd-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 d-print-none';
        const count = document.createElement('div'); count.className = 'small text-muted';
        const paging = document.createElement('div'); paging.dataset.adminTablePaging = '1'; paging.className = 'd-flex align-items-center gap-2';
        const pageLabel = document.createElement('span'); pageLabel.className = 'small text-muted';
        const sizes = document.createElement('select'); sizes.className = 'form-select form-select-sm'; sizes.style.width = 'auto'; sizes.setAttribute('aria-label', 'Rows per page');
        [10,25,50,100,250,500].forEach(size => sizes.add(new Option(String(size), String(size))));
        if (![10,25,50,100,250,500].includes(Number(state.perPage))) state.perPage = 50;
        sizes.value = String(state.perPage);
        const previous = document.createElement('button'), next = document.createElement('button');
        [previous, next].forEach(button => {button.type = 'button'; button.className = 'btn btn-sm btn-outline-secondary';});
        previous.textContent = 'Previous'; next.textContent = 'Next';
        paging.append(sizes, previous, pageLabel, next); controls.append(count, paging);
        const wrapper = table.closest('.table-responsive') || table;
        wrapper.insertAdjacentElement('afterend', controls);
        const noResults = document.createElement('tr');
        const noResultsCell = document.createElement('td'); noResultsCell.colSpan = headers.length; noResultsCell.className = 'text-muted'; noResultsCell.textContent = 'No records match these filters.';
        noResults.appendChild(noResultsCell); body.appendChild(noResults);
        const sortValue = (row, index) => {
            const cell = originalCells.get(row)[index];
            const value = cell.dataset.sortValue || cell.querySelector('time[datetime]')?.dateTime || text(row, index);
            if (/date|created|updated|modified|purchased|sent|scheduled|placed|start|end/i.test(headers[index].textContent)) {
                const uk = value.match(/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})(.*)$/);
                const date = Date.parse(uk ? `${uk[3]}-${uk[2].padStart(2,'0')}-${uk[1].padStart(2,'0')}${uk[4]}` : value.replace(/\s*\(UK\)$/, ''));
                if (Number.isFinite(date)) return date;
            }
            if (/^[£€$]?\s*-?[\d,]+(?:\.\d+)?$/.test(value)) return Number(value.replace(/[£€$,\s]/g,''));
            return value;
        };
        const apply = () => {
            const filtered = rows.filter(row => [...filterControls].every(([index, control]) => {
                const needle = String(state.filters[index] || '').toLocaleLowerCase();
                const value = text(row, index).toLocaleLowerCase();
                return !needle || (control.tagName === 'SELECT' ? needle === value : value.includes(needle));
            }));
            if (state.sort !== null && eligible[state.sort]) filtered.sort((a,b) => {const left=sortValue(a,state.sort), right=sortValue(b,state.sort); return (typeof left === 'number' && typeof right === 'number' ? left-right : collator.compare(String(left),String(right))) * (state.dir === 'desc' ? -1 : 1);});
            const pages = Math.max(1, Math.ceil(filtered.length / Number(state.perPage)));
            state.page = Math.max(1, Math.min(Number(state.page) || 1, pages));
            const start = (state.page - 1) * Number(state.perPage);
            rows.forEach(row => row.hidden = true); originalEmpty.forEach(row => row.hidden = true);
            // Append existing nodes, preserving all values, listeners and form associations.
            filtered.forEach((row,index) => {body.appendChild(row); row.hidden = index < start || index >= start + Number(state.perPage);});
            noResultsCell.colSpan = table.tHead.rows[0].cells.length;
            body.appendChild(noResults); noResults.hidden = filtered.length > 0;
            count.textContent = filtered.length ? `Showing ${start + 1}–${Math.min(start + Number(state.perPage), filtered.length)} of ${filtered.length}` : '0 records';
            paging.hidden = filtered.length <= 25;
            previous.disabled = state.page <= 1; next.disabled = state.page >= pages;
            pageLabel.textContent = `${state.page} / ${pages}`;
            sortButtons.forEach((button,index) => {
                button.querySelector('.sort-arrow').textContent = state.sort === index ? (state.dir === 'asc' ? '↑' : '↓') : '↕';
                headers[index].setAttribute('aria-sort', state.sort === index ? (state.dir === 'asc' ? 'ascending' : 'descending') : 'none');
            });
            storage.set(key, state);
        };
        headers.forEach((header, index) => {
            const cell = document.createElement('th'); filterRow.appendChild(cell);
            if (!eligible[index]) return;
            const label = header.textContent.trim().replace(/[↕↑↓]/g, '').trim();
            if (!header.querySelector('a.sort-link, a[href*="sort="], button, input, select')) {
                const button = document.createElement('button'); button.type = 'button'; button.className = 'btn p-0 border-0 text-start text-dark fw-bold sort-link';
                button.append(document.createTextNode(label));
                const arrow = document.createElement('span'); arrow.className = 'sort-arrow'; arrow.setAttribute('aria-hidden','true'); button.appendChild(arrow);
                header.replaceChildren(button); sortButtons.set(index, button);
                button.addEventListener('click', () => {state.dir = state.sort === index && state.dir === 'asc' ? 'desc' : 'asc'; state.sort = index; state.page = 1; apply();});
            }
            const values = [...new Set(rows.map(row => text(row,index)).filter(Boolean))].sort(collator.compare);
            const categorical = /status|type|category|role|level|year|published|active|group|profile/i.test(label) && values.length <= 30;
            const control = document.createElement(categorical ? 'select' : 'input');
            control.className = categorical ? 'form-select form-select-sm' : 'form-control form-control-sm';
            control.setAttribute('aria-label', `Filter ${label}`); control.dataset.clientTableFilter = '1';
            if (categorical) {control.add(new Option('All','')); values.forEach(value => control.add(new Option(value,value)));}
            else {control.type = 'search'; control.placeholder = 'Search';}
            control.addEventListener('keydown', event => {if (event.key === 'Enter') {event.preventDefault(); event.stopPropagation();}});
            if (categorical && state.filters[index] && !values.includes(state.filters[index])) control.add(new Option(state.filters[index], state.filters[index]));
            control.value = state.filters[index] || '';
            cell.appendChild(control); filterControls.set(index, control);
            control.addEventListener(categorical ? 'change' : 'input', () => {state.filters[index] = control.value; state.page = 1; apply();});
        });
        const clear = document.createElement('button'); clear.type = 'button'; clear.className = 'btn btn-sm btn-outline-secondary'; clear.textContent = 'Clear';
        clear.addEventListener('click', () => {state.filters = {}; state.sort = null; state.dir = 'asc'; state.page = 1; filterControls.forEach(control => control.value = ''); initialRows.forEach(row => body.appendChild(row)); apply();});
        filterRow.lastElementChild.appendChild(clear);
        previous.addEventListener('click', () => {state.page--; apply();});
        next.addEventListener('click', () => {state.page++; apply();});
        sizes.addEventListener('change', () => {state.perPage = Number(sizes.value); state.page = 1; apply();});
        apply();
        // Print reports contain all matching rows, rather than just the current page.
        window.addEventListener('beforeprint', () => {rows.forEach(row => row.hidden = ![...filterControls].every(([index, control]) => {const needle=String(state.filters[index]||'').toLocaleLowerCase(), value=text(row,index).toLocaleLowerCase(); return !needle || (control.tagName==='SELECT'?needle===value:value.includes(needle));}));});
        window.addEventListener('afterprint', apply);
    });
})();
