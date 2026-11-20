const {chromium} = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
(async () => {
    const source = fs.readFileSync('admin/event_edit.php', 'utf8');
    assert.ok(source.includes('<option value="committee" data-phone=""'));
    const start = source.indexOf('    (function() {\n        const organiserSelect');
    const end = source.indexOf('        const startDate =', start);
    assert.ok(start > 0 && end > start);
    const script = source.slice(start, end) + '})();';
    const warning = fs.readFileSync('views/ride_notes_emergency_warning.php', 'utf8').replace(/<\?php[\s\S]*?\?>/g, '');
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage({viewport: {width: 768, height: 1024}});
        await page.setContent(`<form id="event"><select name="organiser_user_id"><option value="committee" data-phone="">Committee</option><option value="2" data-phone="01234 567890">Person</option></select><select name="event_type_id"><option>Ride</option></select><div id="ride-emergency-controls"><input type="checkbox" id="emergency_use_organiser" checked><input id="emergency_phone" type="tel"><div id="emergency-phone-help"></div></div></form><script>${script}</script>`);
        const phone = page.locator('#emergency_phone');
        assert.equal(await phone.getAttribute('required'), null, 'Committee must allow blank phone');
        assert.equal(await page.locator('#emergency_use_organiser').isDisabled(), true);
        assert.equal(await page.locator('#event').evaluate(form => form.checkValidity()), true);
        await phone.fill('01234 123456');
        await page.locator('[name=organiser_user_id]').selectOption('2');
        assert.equal(await phone.inputValue(), '01234 123456', 'Manual number must survive organiser changes');
        await page.locator('#emergency_use_organiser').check();
        assert.equal(await phone.inputValue(), '01234 567890');
        await page.locator('#emergency_use_organiser').uncheck();
        assert.equal(await phone.inputValue(), '01234 123456');
        await page.setContent(`<form><textarea id="notes"></textarea><button id="save">Save Rider Notes</button></form>${warning}`);
        const dialog = page.locator('#ride-emergency-warning');
        assert.equal(await dialog.evaluate(el => el.open), true);
        assert.equal(await page.getByRole('heading', {name: 'No Emergency Number added yet'}).count(), 1);
        await page.getByRole('button', {name: 'Continue', exact: true}).click();
        assert.equal(await dialog.evaluate(el => el.open), false);
        await page.locator('#notes').fill('Notes remain editable.');
        assert.equal(await page.locator('#save').isEnabled(), true);
        console.log('PASS: Committee selection, optional phone, manual/organiser switching and advisory modal Continue preserves editing.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
