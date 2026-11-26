const {chromium}=require('playwright'),fs=require('node:fs'),assert=require('node:assert/strict');
(async()=>{
 const dir=process.argv[2];assert.ok(fs.realpathSync(dir).startsWith('/tmp/'));
 const html=fs.readFileSync(dir+'/entry-contact.html','utf8'),browser=await chromium.launch({headless:true});
 try {
 for(const width of [1440,390])for(const answer of ['Yes','No','complete']) {
  const page=await browser.newPage({viewport:{width,height:800}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',route=>{
   const url=route.request().url();
   if(url.includes('bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js'))return route.fulfill({contentType:'application/javascript',body:fs.readFileSync('/tmp/ildra-bootstrap-5.3.2.bundle.min.js')});
   if(url.includes('bootstrap@5.3.2/dist/css/bootstrap.min.css'))return route.fulfill({contentType:'text/css',body:fs.readFileSync('/tmp/ildra-bootstrap-5.3.2.min.css')});
   for(const name of ['entry_person','entry_horses'])if(url.endsWith(`/assets/${name}.js`))return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(`assets/${name}.js`)});
   if(url.includes('/event.php'))return route.fulfill({contentType:'text/html',body:html});return route.abort();
  });
  await page.goto('http://entry.test/event.php?id=790001');
  await page.evaluate(()=>{
   window.submits=[];document.addEventListener('submit',e=>{
    if(e.target.id==='entryForm'&&!e.defaultPrevented){e.preventDefault();window.submits.push(new FormData(e.target).get('save_rider_contact'));}
   });
  });
  await page.locator('#prefillPerson').selectOption('700002');
  await page.locator('#rideTypeSelect').selectOption(await page.locator('#rideTypeSelect option').evaluateAll(options=>options.find(o=>o.value&&!o.disabled).value));
  await page.locator('#classSelect').selectOption('PR');
  await page.locator('#contactPhone').fill('new-phone');
  await page.locator('#emergencyContactName').fill('New Name');
  await page.locator('#emergencyContactPhone').fill('new-emergency-phone');
  if(answer==='complete')await page.evaluate(()=>{peopleData.find(p=>Number(p.id)===700002).contact_missing.emergency_contact_phone=false;});
  await page.locator('#submitEntry').click();
  if(answer==='complete') {
   assert.equal(await page.locator('#saveEmergencyContactModal').isVisible(),false);
   assert.deepEqual(await page.evaluate(()=>window.submits),['0']);
  } else {
   await page.waitForSelector('#saveEmergencyContactModal.show');
   assert.equal(await page.locator('#riderContactUpdateFields').textContent(),'emergency contact phone');
   assert.deepEqual(await page.evaluate(()=>window.submits),[]);
   await page.locator(answer==='Yes'?'#saveEmergencyContactYes':'#saveEmergencyContactNo').click();
   assert.deepEqual(await page.evaluate(()=>window.submits),[answer==='Yes'?'1':'0']);
  }
  assert.deepEqual(errors,[]);await page.close();
 }
 console.log('PASS: desktop/mobile missing-field-only popup, complete linked rider skips popup, and Yes/No consent submits exactly once.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
