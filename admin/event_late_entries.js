(() => {
    'use strict';
    const config = document.currentScript.dataset;
    const formatter = new Intl.DateTimeFormat('en-GB', {timeZone:'Europe/London',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'});
    const parts = timestamp => Object.fromEntries(formatter.formatToParts(new Date(timestamp)).filter(p=>p.type!=='literal').map(p=>[p.type,p.value]));
    const ukTimestamp = (date,time) => {
        const [y,m,d]=date.split('-').map(Number), [h,min]=time.split(':').map(Number);
        const target=Date.UTC(y,m-1,d,h,min);
        let guess=target;
        for(let i=0;i<4;i++) {
            const p=parts(guess);
            const difference=target-Date.UTC(+p.year,+p.month-1,+p.day,+p.hour,+p.minute,+p.second);
            if(!difference) return guess;
            guess+=difference;
        }
        return NaN;
    };
    document.addEventListener('DOMContentLoaded', () => {
        const field=name=>document.querySelector(`input[name="${name}"]`);
        const date=field('late_entry_open_date'), time=field('late_entry_open_time'), closeDate=field('entry_close_date'), closeTime=field('entry_close_time');
        if(!date || !time || !closeDate || !closeTime) return;
        let generated=config.stored==='1'?null:[date.value,time.value];
        const update=()=>{
            if((date.value || time.value) && (!generated || date.value!==generated[0] || time.value!==generated[1])) return;
            if(!closeDate.value || !closeTime.value) return;
            const timestamp=ukTimestamp(closeDate.value,closeTime.value);
            if(!Number.isFinite(timestamp)) return;
            const p=parts(timestamp+Number(config.reopenHours)*3600000);
            date.value=`${p.year}-${p.month}-${p.day}`;time.value=`${p.hour}:${p.minute}`;
            generated=[date.value,time.value];
        };
        for(const el of [closeDate,closeTime,field('event_date')]) {
            el?.addEventListener('input',update);el?.addEventListener('change',update);
        }
        update();
    });
})();
