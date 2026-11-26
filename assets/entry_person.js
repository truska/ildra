(() => {
    const modalEl = document.getElementById('entryPersonModal');
    const form = document.getElementById('entryPersonForm');
    if (!modalEl || !form) return;
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const details = document.getElementById('entryPersonDetails');
    const email = form.elements.email;
    const submit = document.getElementById('entryPersonSubmit');
    const generalError = document.getElementById('entryPersonError');
    const clearErrors = () => {
        generalError.classList.add('d-none');
        form.querySelectorAll('[data-field-error]').forEach(el => el.remove());
        form.querySelectorAll('.is-invalid').forEach(el => {
            el.classList.remove('is-invalid');el.removeAttribute('aria-invalid');el.removeAttribute('aria-describedby');
        });
    };
    const showErrors = result => {
        clearErrors();
        for (const [name, message] of Object.entries(result.errors || {})) {
            const field = form.elements.namedItem(name);
            if (!field) continue;
            const warning = document.createElement('div');
            warning.id = field.id + '_error';warning.dataset.fieldError = '1';
            warning.className = 'text-danger small mb-1';warning.textContent = message;
            field.before(warning);field.classList.add('is-invalid');
            field.setAttribute('aria-invalid','true');field.setAttribute('aria-describedby',warning.id);
        }
        if (result.message) { generalError.textContent = result.message;generalError.classList.remove('d-none'); }
        form.querySelector('.is-invalid')?.focus();
    };
    document.addEventListener('entry-add-person', () => {
        form.reset();form.elements.dob.required = false;details.hidden = true;clearErrors();submit.textContent = 'Continue';modal.show();
    });
    modalEl.addEventListener('shown.bs.modal', () => email.focus());
    form.elements.junior_or_senior.addEventListener('change', () => { form.elements.dob.required = form.elements.junior_or_senior.value === 'Junior'; });
    email.addEventListener('input', () => {
        if (form.elements.stage.value !== 'details') return;
        form.elements.stage.value = 'email';details.hidden = true;submit.textContent = 'Continue';
        details.querySelectorAll('input,textarea,select').forEach(field => { field.value = ''; });
        form.elements.dob.required = false;
        clearErrors();
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (submit.disabled) return;
        clearErrors();submit.disabled = true;
        try {
            const response = await fetch(form.action,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{Accept:'application/json'}});
            const result = await response.json();
            if (result.status === 'details') {
                email.value = result.email;form.elements.stage.value = 'details';details.hidden = false;
                submit.textContent = 'Add Person';form.elements.first_name.focus();
            } else if (result.status === 'ready') {
                // Refresh only rider data/options: entered horse, contact and component fields stay intact.
                const refreshed = await fetch(window.location.href,{credentials:'same-origin',cache:'no-store'});
                if (!refreshed.ok) throw new Error('refresh');
                const page = new DOMParser().parseFromString(await refreshed.text(),'text/html');
                const select = page.getElementById('prefillPerson');
                const json = page.getElementById('entryRiderData');
                if (!select || !json || !Array.from(select.options).some(option => option.value === String(result.person_id))) throw new Error('refresh');
                document.dispatchEvent(new CustomEvent('entry-riders-refreshed',{detail:{data:JSON.parse(json.textContent),select,personId:result.person_id}}));
                modal.hide();
                modalEl.addEventListener('hidden.bs.modal', () => document.getElementById('prefillPerson')?.focus(),{once:true});
            } else showErrors(result);
        } catch (error) {
            showErrors({message:'The rider could not be loaded. Please try again. Any saved rider will be reused.'});
        } finally { submit.disabled = false; }
    });
})();
