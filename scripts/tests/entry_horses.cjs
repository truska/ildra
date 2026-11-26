const {chromium}=require('playwright');
const fs=require('node:fs'),assert=require('node:assert/strict');
(async()=>{
 const directory=process.argv[2];assert.ok(fs.realpathSync(directory).startsWith('/tmp/'));
 const html=fs.readFileSync(directory+'/entry-horses.html','utf8');
 const browser=await chromium.launch({headless:true});
 try {
 for(const width of [1440,390]) {
  const page=await browser.newPage({viewport:{width,height:800}}),errors=[];let posts=0;
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',route=>{
   const url=route.request().url();
   if(url.includes('bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js'))return route.fulfill({contentType:'application/javascript',body:fs.readFileSync('/tmp/ildra-bootstrap-5.3.2.bundle.min.js')});
   if(url.includes('bootstrap@5.3.2/dist/css/bootstrap.min.css'))return route.fulfill({contentType:'text/css',body:fs.readFileSync('/tmp/ildra-bootstrap-5.3.2.min.css')});
   for(const name of ['entry_person','entry_horses'])if(url.endsWith(`/assets/${name}.js`))return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(`assets/${name}.js`)});
   if(url.includes('/entry_horse.php')) {
    posts++;const body=route.request().postData();assert.match(body,/name="csrf"\r\n\r\n[^\r]+/);assert.match(body,/name="person_id"\r\n\r\n123/);assert.match(body,/name="horse_id"\r\n\r\n810003/);
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,horse_id:810003})});
   }
   if(url.includes('/event.php'))return route.fulfill({contentType:'text/html',body:html});
   return route.abort();
  });
  await page.goto('http://entry.test/event.php?id=790001');
  const rider=page.locator('#prefillPerson'),horse=page.locator('#prefillHorse'),toggle=page.locator('#horseSelectionToggle');
  assert.equal(await page.locator('#horseSelectionToggleWrap').isVisible(),false);
  await rider.selectOption('123');assert.equal(await horse.isEnabled(),true);
  await horse.dispatchEvent('change');
  assert.equal(await page.locator('[data-validation-for="prefill_horse"]').isVisible(),true);
  await rider.selectOption('810001');
  assert.equal(await page.locator('[data-validation-for="prefill_horse"]').isVisible(),false);
  assert.equal(await horse.evaluate(el=>el.classList.contains('is-invalid')),false);
  await rider.selectOption('123');
  let order=await horse.evaluate(el=>Array.from(el.children).map(x=>x.tagName==='HR'?'divider':x.value));
  assert.deepEqual(order,['','810001','810002','divider','810007','810003']);
  assert.equal(await toggle.isVisible(),true);assert.equal(await toggle.isChecked(),true);
  assert.equal(await horse.locator('option[value="1"]').count(),0);
  await toggle.uncheck();assert.equal(posts,0);assert.equal(await horse.isDisabled(),true);
  assert.equal(await horse.inputValue(),'1');assert.equal(await page.locator('#horseSelectionMode').inputValue(),'not_registered');
  assert.equal(await page.locator('#horseName').inputValue(),'Not Registered');
  assert.equal(await page.locator('[data-validation-for="prefill_horse"]').isVisible(),false);
  await toggle.check();assert.equal(await horse.isEnabled(),true);assert.equal(await horse.inputValue(),'');
  assert.equal(await page.locator('#horseSelectionMode').inputValue(),'registered');
  await horse.selectOption('810001');assert.equal(posts,0);
  await horse.selectOption('810002');assert.equal(posts,0);
  await horse.selectOption('810003');await page.waitForFunction(()=>document.querySelector('#prefillHorse').children[3]?.value==='810003');
  assert.equal(posts,1);assert.equal(await horse.inputValue(),'810003');
  assert.equal(await page.locator('#horseName').inputValue(),'Other Beta');
  order=await horse.evaluate(el=>Array.from(el.children).map(x=>x.tagName==='HR'?'divider':x.value));
  assert.deepEqual(order,['','810001','810002','810003','divider','810007']);
  await toggle.uncheck();await toggle.check();assert.equal(await horse.inputValue(),'810003');assert.equal(posts,1);
  await rider.selectOption('810001');assert.equal(await horse.isDisabled(),true);assert.equal(await horse.inputValue(),'1');
  assert.equal(await page.locator('#horseId').inputValue(),'1');assert.equal(await page.locator('#horseName').inputValue(),'Not Registered');
  assert.equal(await horse.getAttribute('data-required'),'false');
  assert.equal(await toggle.isVisible(),false);
  await rider.selectOption('123');assert.equal(await toggle.isChecked(),true);assert.equal(await horse.isEnabled(),true);
  await toggle.uncheck();await rider.selectOption('810002');assert.equal(await horse.isEnabled(),true);assert.equal(await toggle.isVisible(),false);
  await horse.selectOption('810003');assert.equal(posts,1);
  assert.deepEqual(errors,[]);await page.close();
 }
 console.log('PASS: desktop/mobile horse ordering, divider, automatic link request, remembered selection, canonical fields and member/nonmember/recognised rider switching.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
