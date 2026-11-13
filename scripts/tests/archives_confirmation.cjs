const {chromium} = require('playwright');
const fs = require('fs');
const assert = require('node:assert/strict');
(async () => {
    const isVenue=process.argv.includes('--venues');
    const isEvent=process.argv.includes('--events');
    const isAnnouncement=process.argv.includes('--announcements');
    const source=fs.readFileSync(isVenue?'admin/venues.php':isEvent?'admin/events.php':isAnnouncement?'admin/archive_announcements.php':'admin/archives.php','utf8');
    let forms=[...source.matchAll(/<form method="post"[\s\S]*?<\/form>/gi)].map(match=>match[0].replace(/<\?php[\s\S]*?\?>/g, php=>php.includes('csrf_input')?'<input type="hidden" name="csrf" value="fixture-token">':php.includes('$blockers')?'':(php.includes("$page['id']") || php.includes("$notice['id']") || php.includes("$event['id']") || php.includes("$thirdPartyEvent['id']") || php.includes("$venue['id']"))?'2':'Fixture page'));
    if(isEvent||isVenue) forms=forms.filter(form=>form.includes('data-confirm-require-text="DELETE"')).slice(0,1);
    assert.equal(forms.length,(isEvent||isVenue)?1:2);
    const bootstrap=fs.readFileSync('admin/_bootstrap.php','utf8');
    const modalStart=bootstrap.indexOf('<div class="modal fade" id="sharedConfirmModal"');
    const scriptStart=bootstrap.indexOf('<script>',modalStart);
    const modal=bootstrap.slice(modalStart,scriptStart);
    const script=bootstrap.slice(scriptStart+8,bootstrap.indexOf('</script>',scriptStart));
    const stub=`window.bootstrap={Modal:{getOrCreateInstance(el){return {show(){el.hidden=false},hide(){el.hidden=true;el.dispatchEvent(new Event('hidden.bs.modal'))}}}}};document.addEventListener('click',e=>{if(e.target.matches('[data-bs-dismiss="modal"]'))bootstrap.Modal.getOrCreateInstance(document.getElementById('sharedConfirmModal')).hide()});`;
    const browser=await chromium.launch({headless:true});
    try {
        const page=await browser.newPage();
        const errors=[];page.on('pageerror',e=>errors.push(e.message));
        await page.setContent(forms.join('')+modal+`<script>${stub}</script><script>${script}</script><script>window.submissions=[];document.addEventListener('DOMContentLoaded',()=>document.addEventListener('submit',e=>{if(!e.defaultPrevented){e.preventDefault();submissions.push(Object.fromEntries(new FormData(e.target)))}}));</script>`);
        await page.locator('#sharedConfirmModal').evaluate(el=>el.hidden=true);
        for (const [action,label] of (isVenue?[['archive_venue','Archive']]:isEvent?[['archive_event','Archive']]:[['restore','Restore as Draft'],['purge','Permanently Delete']])) {
            const button=page.locator(`form:has(input[value="${action}"]) button`);
            const before=await page.evaluate(()=>submissions.length);
            await button.click();
            await page.locator('#sharedConfirmModal').waitFor({state:'visible'});
            assert.equal(await page.locator('#sharedConfirmButton').textContent(),label);
            assert.equal(await page.evaluate(()=>submissions.length),before,'No submit before confirmation');
            await page.locator('#sharedConfirmModal [data-bs-dismiss="modal"]').last().click();
            assert.equal(await page.evaluate(()=>submissions.length),before,'Cancel must not submit');
            await button.click();
            const confirm=page.locator('#sharedConfirmButton');
            const input=page.locator('#sharedConfirmText');
            if((action==='purge'||(action==='archive_event'||action==='archive_venue'))) {
                assert.equal(await confirm.isDisabled(),true,'Deletion starts disabled');
                for(const value of ['delete','Delete',' DELETE','DELETE ']) {
                    await input.fill(value);
                    assert.equal(await confirm.isDisabled(),true,'Require exact uppercase DELETE');
                }
                await input.fill('DELETE');
                assert.equal(await confirm.isEnabled(),true);
                await page.locator('#sharedConfirmModal [data-bs-dismiss="modal"]').last().click();
                await button.click();
                assert.equal(await input.inputValue(),'','Reopening clears confirmation');
                assert.equal(await confirm.isDisabled(),true);
                await input.fill('DELETE');
            } else {
                assert.equal(await page.locator('#sharedConfirmTextGroup').isHidden(),true);
                assert.equal(await confirm.isEnabled(),true);
            }
            await confirm.click();
            await page.waitForFunction(n=>submissions.length===n,before+1);
            const submitted=await page.evaluate(()=>submissions.at(-1));
            if((action==='purge'||(action==='archive_event'||action==='archive_venue'))) assert.equal(submitted.delete_confirmation,'DELETE');
            assert.equal(submitted.action,action);assert.equal(submitted[isVenue?'id':isEvent?'event_id':isAnnouncement?'announcement_id':'page_id'],'2');
            if(isAnnouncement) assert.equal(submitted.tab,'announcements');assert.equal(submitted.csrf,'fixture-token');
        }
        assert.deepEqual(errors,[]);
        console.log('Archive forms: restore/purge confirmation, cancellation, single submission and CSRF preservation passed (fixture browser).');
    } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
