(() => {
    'use strict';
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('input[type="time"][step="300"], input[type="time"][step="900"]').forEach(input => {
            const quarterHours = input.step === '900';
            const increment = quarterHours ? 15 : 5;
            const label = input.closest('div')?.querySelector('label');
            const title = label?.textContent.trim() || input.name;
            const group = document.createElement('div');
            group.className = quarterHours ? 'event-time-parts' : 'd-flex align-items-center gap-2';
            if (quarterHours) {
                Object.assign(group.style, {display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) auto minmax(0, 1fr)', gap: '0.25rem', alignItems: 'center', width: '100%'});
            }
            const makeSelect = (count, increment, name) => {
                const select = document.createElement('select');
                select.className = 'form-select';
                select.setAttribute('aria-label', `${title}: ${name}`);
                select.required = input.required;
                select.disabled = input.disabled;
                if (quarterHours) {
                    Object.assign(select.style, {width: '100%', minWidth: '0', fontSize: '16px', minHeight: '44px', paddingLeft: '0.4rem', paddingRight: '1.35rem', backgroundPosition: 'right 0.3rem center'});
                    for (const attribute of ['aria-describedby', 'aria-invalid']) {
                        if (input.hasAttribute(attribute)) select.setAttribute(attribute, input.getAttribute(attribute));
                    }
                }
                select.add(new Option(quarterHours ? (name === 'Hour' ? 'HH' : 'MM') : name, ''));
                for (let i=0;i<count;i++) {
                    const value=String(i*increment).padStart(2,'0');
                    select.add(new Option(value,value));
                }
                return select;
            };
            const hour = makeSelect(24,1,'Hour');
            const minute = makeSelect(60/increment,increment,'Minute');
            if(input.id || quarterHours) {
                const id = input.id || input.name;
                hour.id=id+'-hour';minute.id=id+'-minute';
                if(label && (quarterHours || label.htmlFor===input.id)) label.htmlFor=hour.id;
            }
            const separator=document.createElement('span');separator.textContent=':';separator.setAttribute('aria-hidden','true');
            group.append(hour,separator,minute);
            const sync = () => {
                const [h,m]=(input.value || '').split(':');
                minute.querySelectorAll('[data-existing]').forEach(option=>option.remove());
                // Keep historic values intact until the administrator changes them.
                if(m && Number(m)%increment!==0) {
                    const option=new Option(quarterHours ? m : `${m} (existing)`,m);option.dataset.existing='1';option.title='Existing saved minute';minute.add(option);
                }
                hour.value=h || '';minute.value=m || '';
            };
            sync();
            // Hidden inputs retain their names, posted values and the existing schedule scripts.
            // Their value property reflects the attribute, so generated dates/times stay in sync.
            input.type='hidden';
            input.after(group);
            new MutationObserver(sync).observe(input,{attributes:true,attributeFilter:['value']});
            input.addEventListener('input',sync);input.addEventListener('change',sync);
            const update = event => {
                if(event.target.value) {
                    if(!hour.value) hour.value='00';
                    if(!minute.value) minute.value='00';
                } else { hour.value='';minute.value=''; }
                input.value=hour.value && minute.value ? `${hour.value}:${minute.value}` : '';
                input.dispatchEvent(new Event('input',{bubbles:true}));
                input.dispatchEvent(new Event('change',{bubbles:true}));
            };
            hour.addEventListener('change',update);minute.addEventListener('change',update);
        });
    });
})();
