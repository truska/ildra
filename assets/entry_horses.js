(() => {
    const rider = document.getElementById('prefillPerson');
    const select = document.getElementById('prefillHorse');
    const dataEl = document.getElementById('entryRiderData');
    const form = document.getElementById('entryForm');
    if (!rider || !select || !dataEl || !form) return;
    const toggle = document.getElementById('horseSelectionToggle');
    const toggleWrap = document.getElementById('horseSelectionToggleWrap');
    const selectionMode = document.getElementById('horseSelectionMode');
    let activeRiderId = null, previousHorse = '';
    const member = () => rider.selectedOptions[0]?.dataset.memberActive === '1';
    const remembered = new Set();
    const error = document.getElementById('entryHorseError');
    let rebuilding = false;
    const data = () => JSON.parse(dataEl.textContent);
    const eligible = () => rider.selectedOptions[0]?.dataset.memberActive === '1' || rider.selectedOptions[0]?.dataset.externalRecognition === '1';
    const option = (id, name, recognised = false) => {
        const el = new Option(name, String(id));el.dataset.horseName = id ? name : '';
        el.dataset.recognised = recognised ? '1' : '0';return el;
    };
    const build = (preferred = select.value) => {
        const payload = data(), catalog = payload.horse_catalogue;
        (catalog.remembered || []).forEach(id => remembered.add(Number(id)));
        const pid = Number(rider.value || 0);
        if (activeRiderId !== pid) { toggle.checked = true;previousHorse = '';activeRiderId = pid; }
        toggleWrap.hidden = !member();toggle.disabled = !member();
        const selectionOff = member() && !toggle.checked;
        selectionMode.value = selectionOff ? 'not_registered' : 'registered';
        const canChoose = pid > 0 && eligible() && !selectionOff;
        const required = document.getElementById('horseRequiredMark');
        required.hidden = !canChoose;select.required = canChoose;select.dataset.required = canChoose ? 'true' : 'false';
        select.disabled = !canChoose;error.classList.add('d-none');select.classList.remove('is-invalid');
        form.querySelector('[data-validation-for="prefill_horse"]')?.classList.add('d-none');
        if (!pid) select.replaceChildren(option('', 'Choose a rider first...'));
        else if (!canChoose) select.replaceChildren(option(1, 'Not Registered'));
        else {
            const horses = catalog.horses || [];
            const own = new Set((catalog.own_by_person?.[pid] || []).map(Number));
            const linked = new Set((catalog.linked_by_person?.[pid] || []).map(Number));
            remembered.forEach(id => { if (!own.has(id)) linked.add(id); });
            const ownedHorses = horses.filter(horse => own.has(horse.id));
            const linkedHorses = horses.filter(horse => !own.has(horse.id) && linked.has(horse.id));
            const otherHorses = horses.filter(horse => !own.has(horse.id) && !linked.has(horse.id));
            select.replaceChildren(option('', 'Choose...'));
            [...ownedHorses, ...linkedHorses].forEach(horse => select.append(option(horse.id,horse.name,true)));
            const divider = () => {
                const rule = document.createElement('hr');rule.setAttribute('aria-hidden','true');select.append(rule);
            };
            if (member()) {
                if ((ownedHorses.length || linkedHorses.length) && otherHorses.length) divider();
            } else { divider();select.append(option(1,'Not Registered'));divider(); }
            otherHorses.forEach(horse => select.append(option(horse.id,horse.name,true)));
            select.value = (!member() && String(preferred) === '1') || horses.some(horse => String(horse.id) === String(preferred)) ? String(preferred) : '';
        }
        document.getElementById('entryHorseHint').textContent = !pid ? 'Choose a rider first.' : (!canChoose ? (selectionOff ? 'Not Registered selected. Switch on to choose an eligible horse.' : 'No horse selection is required for this rider.') : 'Own and linked horses are listed first, followed by other horses with a valid logbook or recognition.');
        rebuilding = true;select.dispatchEvent(new Event('change',{bubbles:true}));rebuilding = false;
    };
    toggle.addEventListener('change', () => {
        if (!toggle.checked) previousHorse = select.value;
        build(toggle.checked ? previousHorse : '1');
    });
    rider.addEventListener('change', () => { if (rider.value !== 'another') build(); });
    select.addEventListener('change', async () => {
        if (rebuilding || !eligible()) return;
        const horseId = Number(select.value || 0), personId = Number(rider.value || 0);
        if (horseId <= 1 || remembered.has(horseId)) return;
        const payload = data(), body = new FormData();
        body.set('csrf',form.elements.namedItem('csrf')?.value || '');body.set('event_id',String(payload.event_id));
        body.set('person_id',String(personId));body.set('horse_id',String(horseId));
        try {
            const response = await fetch(payload.horse_endpoint,{method:'POST',body,credentials:'same-origin',headers:{Accept:'application/json'}});
            const result = await response.json();
            if (!result.ok) throw new Error(result.message || 'The horse could not be linked. Please try again.');
            remembered.add(horseId);
            if (Number(select.value) === horseId && Number(rider.value) === personId) build(horseId);
        } catch (failure) {
            if (Number(select.value) !== horseId || Number(rider.value) !== personId) return;
            select.value = '';rebuilding = true;select.dispatchEvent(new Event('change',{bubbles:true}));rebuilding = false;
            error.textContent = failure.message || 'The horse could not be linked. Please try again.';error.classList.remove('d-none');
        }
    });
    build();
})();
