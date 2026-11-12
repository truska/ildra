const {chromium}=require('playwright');
const fs=require('fs');
const assert=require('node:assert/strict');
(async()=>{
    const browser=await chromium.launch({headless:true});
    try {
        const context=await browser.newContext({timezoneId:'America/New_York'});
        const page=await context.newPage();
        const script=fs.readFileSync('admin/event_late_entries.js','utf8');
        const fixture=async(date,time,hours=6,stored=false)=>{
            await page.setContent(`<input name="event_date" type="date"><input name="entry_close_date" type="date" value="${date}"><input name="entry_close_time" type="time" value="${time}"><input name="late_entry_open_date" type="date" value="${stored?'2030-07-13':''}"><input name="late_entry_open_time" type="time" value="${stored?'09:15':''}"><script data-reopen-hours="${hours}" data-stored="${stored?'1':'0'}">${script}</script>`);
        };
        const value=async name=>page.locator(`[name="${name}"]`).inputValue();
        await fixture('2030-07-12','22:00');
        assert.equal(await value('late_entry_open_date'),'2030-07-13');assert.equal(await value('late_entry_open_time'),'04:00');
        await page.locator('[name="entry_close_time"]').fill('23:30');
        assert.equal(await value('late_entry_open_time'),'05:30');
        await page.locator('[name="late_entry_open_time"]').fill('08:00');
        await page.locator('[name="entry_close_time"]').fill('20:00');
        assert.equal(await value('late_entry_open_time'),'08:00','Manual override changed');
        await fixture('2030-07-12','22:00',9);
        assert.equal(await value('late_entry_open_time'),'07:00','Custom default ignored');
        await fixture('2030-07-12','22:00',6,true);
        assert.equal(await value('late_entry_open_time'),'09:15','Stored snapshot replaced');
        await page.locator('[name="entry_close_time"]').fill('23:00');
        assert.equal(await value('late_entry_open_time'),'09:15','Stored snapshot changed with normal closing');
        await fixture('2026-03-28','23:00');assert.equal(await value('late_entry_open_time'),'06:00','Spring DST must use elapsed hours');
        await fixture('2026-10-24','23:00');assert.equal(await value('late_entry_open_time'),'04:00','Autumn DST must use elapsed hours');
        console.log('Late reopening editor: defaults, midnight rollover, custom/stored overrides and Europe/London DST from another browser timezone passed.');
    } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
