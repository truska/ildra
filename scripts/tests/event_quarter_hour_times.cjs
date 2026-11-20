const {chromium} = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
    const source = fs.readFileSync('admin/event_edit.php', 'utf8');
    const fields = [...source.matchAll(/type="time" step="900" min="00:00" name="([^"]+)"/g)].map(match => match[1]);
    assert.deepEqual(fields, ['start_time', 'end_time', 'entry_open_time', 'non_member_entry_open_time', 'entry_close_time', 'late_entry_open_time', 'late_entry_close_time']);
    assert.equal((source.match(/type="time"/g) || []).length, 7);
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage({viewport: {width: 768, height: 1024}});
        const values = {start_time: '09:00', end_time: '17:00', entry_open_time: '09:15', non_member_entry_open_time: '09:30', entry_close_time: '18:00', late_entry_close_time: '23:59'};
        const controls = fields.map(name => `<div class="column"><label>${name}</label><input type="time" step="900" name="${name}" value="${values[name] || ''}"></div>`).join('');
        await page.setContent(`<style>*{box-sizing:border-box}form{width:calc(100vw - 64px);display:flex;flex-wrap:wrap}.column{width:50%;padding:8px;min-width:0}@media(min-width:992px){form{width:calc(100vw - 320px)}}@media(min-width:1400px){.column{width:25%}}label{display:block;overflow-wrap:anywhere}.form-select{display:block;padding:6px;width:100%}</style><form><input name="entry_close_date" type="date" value="2030-07-12"><input name="late_entry_open_date" type="date">${controls}</form><script>${fs.readFileSync('admin/five_minute_times.js', 'utf8')}</script><script data-reopen-hours="6" data-stored="0">${fs.readFileSync('admin/event_late_entries.js', 'utf8')}</script>`);
        assert.equal(await page.locator('select').count(), 14, 'Must have an hour and minute selector per time field');
        const options = await page.locator('#start_time-minute option').evaluateAll(elements => elements.map(option => option.value).filter(Boolean));
        assert.deepEqual(options, ['00', '15', '30', '45']);
        assert.equal(await page.locator('#start_time-hour option').count(), 25);
        for (const field of fields) {
            assert.equal(await page.locator(`[name="${field}"]`).getAttribute('type'), 'hidden');
            assert.equal(await page.locator(`#${field}-hour`).inputValue(), (await page.locator(`[name="${field}"]`).inputValue()).split(':')[0]);
        }
        for (const width of [768, 820, 1024, 1180, 1366, 1440]) {
            await page.setViewportSize({width, height: 1024});
            const fraction = await page.locator('.column').first().evaluate(column => column.getBoundingClientRect().width / column.closest('form').getBoundingClientRect().width);
            assert.ok(Math.abs(fraction - (width < 1400 ? 0.5 : 0.25)) < 0.01, 'iPad must use a single date/time pair per row');
            for (const field of fields) {
                for (const part of ['hour', 'minute']) {
                    assert.ok(await page.locator(`#${field}-${part}`).evaluate(select => {
                        const box = select.getBoundingClientRect(), container = select.closest('.column').getBoundingClientRect();
                        return box.left >= container.left && box.right <= container.right && box.height >= 44 && box.width >= 60 && getComputedStyle(select).fontSize === '16px';
                    }), `Unreadable or overflowing ${field} ${part} at ${width}px`);
                }
            }
        }
        await page.locator('#entry_close_time-minute').selectOption('15');
        await page.waitForFunction(() => document.getElementById('late_entry_open_time-minute').value === '15');
        await page.locator('#late_entry_open_time-hour').selectOption('01');
        await page.locator('#late_entry_open_time-minute').selectOption('30');
        await page.locator('#entry_close_time-hour').selectOption('19');
        await page.locator('#entry_close_time-minute').selectOption('00');
        assert.equal(await page.locator('[name=late_entry_open_time]').inputValue(), '01:30', 'Manual reopening override must persist');
        assert.equal(await page.locator('#late_entry_close_time-minute').inputValue(), '59', 'Existing non-quarter time must not change silently');
        await page.locator('#late_entry_close_time-minute').selectOption('45');
        assert.equal(await page.locator('#late_entry_close_time-minute [data-existing]').count(), 0);
        await page.locator('#end_time-hour').selectOption('');
        assert.equal(await page.locator('[name=end_time]').inputValue(), '');
        await page.locator('#end_time-hour').selectOption('17');
        assert.equal(await page.locator('[name=end_time]').inputValue(), '17:00', 'Selecting an hour defaults missing minutes to 00');
        await page.locator('[name=start_time]').evaluate(input => { input.value = '10:30'; });
        await page.waitForFunction(() => document.getElementById('start_time-minute').value === '30');
        const data = await page.locator('form').evaluate(form => Object.fromEntries(new FormData(form)));
        assert.equal(data.start_time, '10:30');
        assert.equal(data.entry_close_time, '19:00');
        assert.equal(data.late_entry_close_time, '23:45');
        console.log('PASS: all seven event hour/minute pairs, quarter-hour options, six tablet/desktop widths, existing values, generated reopening, manual overrides and posted times.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exit(1); });
