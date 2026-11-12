const {chromium}=require('playwright');
const fs=require('fs');
const assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({headless:true});
 try {
  const page=await browser.newPage();
  const times=fs.readFileSync('admin/five_minute_times.js','utf8');
  const reopen=fs.readFileSync('admin/event_late_entries.js','utf8');
  await page.setContent(`<form><div><label for="closing">Normal closing</label><input type="date" name="entry_close_date" value="2030-07-12"><input id="closing" name="entry_close_time" type="time" step="300" value="18:00" required></div><div><label>Reopening</label><input name="late_entry_open_date" type="date"><input name="late_entry_open_time" type="time" step="300"></div><div><label>Optional time</label><input name="optional" type="time" step="300"></div><div><label>Historic time</label><input name="historic" type="time" step="300" value="10:07"></div></form><script>${times}</script><script data-reopen-hours="6" data-stored="0">${reopen}</script>`);
  const options=await page.locator('#closing-minute option').evaluateAll(os=>os.map(o=>o.value).filter(Boolean));
  assert.deepEqual(options,['00','05','10','15','20','25','30','35','40','45','50','55']);
  await page.locator('#closing-minute').selectOption('15');
  assert.equal(await page.locator('[name=entry_close_time]').inputValue(),'18:15');
  assert.equal(await page.locator('[name=late_entry_open_time]').inputValue(),'00:15');
  const reopening=page.locator('[name=late_entry_open_time] + div select').last();
  await reopening.selectOption('30');
  await page.locator('#closing-minute').selectOption('20');
  assert.equal(await page.locator('[name=late_entry_open_time]').inputValue(),'00:30','Manual reopen override replaced');
  await page.locator('[name=optional] + div select').first().selectOption('09');
  assert.equal(await page.locator('[name=optional]').inputValue(),'09:00','Empty optional time cannot be entered');
  assert.equal(await page.locator('[name=historic]').inputValue(),'10:07','Historic time rounded silently');
  await page.locator('[name=entry_close_time]').evaluate(el=>{el.value='19:45'});
  await page.waitForFunction(()=>document.getElementById('closing-minute').value==='45');
  const data=await page.locator('form').evaluate(form=>Object.fromEntries(new FormData(form)));
  assert.equal(data.entry_close_time,'19:45');assert.equal(data.late_entry_open_time,'00:30');
  console.log('Five-minute selectors: minute choices, posted values, empty/historic times, generated schedules and reopening overrides passed.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
