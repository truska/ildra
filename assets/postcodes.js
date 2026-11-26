(() => {
    const matches = field => field instanceof HTMLInputElement && (/(?:^|[_\[])(?:postcode|postal_code)(?:\]|$)/i.test(field.name || '') || /\.(?:postcode|postal_code)$/i.test(field.dataset.prefill || ''));
    const clean = value => value.replace(/[^A-Za-z0-9 ]/g, '').toUpperCase();
    const format = field => {
        const start = field.selectionStart, end = field.selectionEnd, original = field.value;
        const value = clean(original);
        if (value !== original) {
            field.value = value;
            if (start !== null && end !== null) field.setSelectionRange(clean(original.slice(0,start)).length,clean(original.slice(0,end)).length);
        }
    };
    const initialise = root => {
        const fields = root instanceof HTMLInputElement ? [root] : root.querySelectorAll('input');
        fields.forEach(field => {
            if (!matches(field)) return;
            if (field.type === 'number') field.type = 'text';
            field.style.textTransform = 'uppercase';
            field.setAttribute('autocapitalize','characters');field.setAttribute('spellcheck','false');
            field.setAttribute('pattern','[A-Z0-9 ]*');
            if (!field.hasAttribute('autocomplete')) field.setAttribute('autocomplete','postal-code');
            format(field);
        });
    };
    document.addEventListener('input', event => { if (matches(event.target)) format(event.target); });
    document.addEventListener('change', event => { if (matches(event.target)) format(event.target); });
    document.addEventListener('focusin', event => { if (matches(event.target)) format(event.target); });
    document.addEventListener('focusout', event => { if (matches(event.target)) event.target.value = clean(event.target.value).trim(); });
    document.addEventListener('submit', event => {
        if (!(event.target instanceof HTMLFormElement)) return;
        Array.from(event.target.elements).forEach(field => { if (matches(field)) field.value = clean(field.value).trim(); });
    },true);
    initialise(document);
    new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node => {
        if (node instanceof Element) initialise(node);
    }))).observe(document.documentElement,{childList:true,subtree:true});
})();
