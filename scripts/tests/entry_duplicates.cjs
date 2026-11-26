const {chromium}=require('playwright'),fs=require('fs'),assert=require('assert/strict');
(async()=>{const dir=process.argv[2];assert.ok(fs.realpathSync(dir).startsWith('/tmp/'));const b=await chromium.launch({headless:true});try{
 for(const width of [1440,390]){
  const p=await b.newPage({viewport:{width,height:800}}),errors=[];p.on('pageerror',e=>errors.push(e.message));
  await p.route('**/*',r=>{const u=r.request().url();
   if(u.includes('bootstrap.min.css'))return r.fulfill({contentType:'text/css',body:fs.readFileSync('/tmp/ildra-bootstrap-5.3.2.min.css')});
   if(u.includes('bootstrap.bundle.min.js'))return r.fulfill({contentType:'application/javascript',body:fs.readFileSync('/tmp/ildra-bootstrap-5.3.2.bundle.min.js')});
   for(const name of ['entry_person','entry_horses'])if(u.endsWith(`/assets/${name}.js`))return r.fulfill({contentType:'application/javascript',body:fs.readFileSync(`assets/${name}.js`)});
   if(u.includes('/event.php'))return r.fulfill({contentType:'text/html',body:fs.readFileSync(dir+'/entry-duplicates.html')});return r.abort();
  });
  await p.goto('http://entry.test/event.php?id=790001');const notice=p.locator('#entryDuplicateNotice');
  const rider=p.locator('#prefillPerson');
  assert.equal(await rider.locator('option[value="123"]').isDisabled(),true);
  assert.match(await rider.locator('option[value="123"]').textContent(),/\(already entered\)$/);
  assert.equal(await rider.locator('option[value="700002"]').isDisabled(),true);
  assert.equal(await rider.locator('option[value="810001"]').isDisabled(),false);
  // A stale/tampered selected value still shows the exact message and cannot submit.
  await rider.evaluate(el=>{el.value='123';el.dispatchEvent(new Event('change',{bubbles:true}));});
  assert.equal(await notice.innerText(),'This rider is already entered for this event.');
  assert.equal(await p.locator('#entryForm').evaluate(el=>el.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}))),false);
  assert.equal(await notice.innerText(),'This rider is already entered for this event.');
  await rider.selectOption('810002');await p.locator('#prefillHorse').selectOption('810001');
  assert.equal(await notice.isVisible(),false);
  assert.equal(await p.locator('#entryHorseDuplicateNotice').innerText(),'This horse is already entered in this event.');
  await p.locator('#rideTypeSelect').selectOption('PR');await p.locator('#classSelect').selectOption('810101');
  assert.equal(await p.locator('#submitEntry').isEnabled(),true);
  // Email-first reuse must not select a rider who has already entered.
  await p.evaluate(()=>{const data=JSON.parse(document.getElementById('entryRiderData').textContent);const select=document.getElementById('prefillPerson').cloneNode(true);document.dispatchEvent(new CustomEvent('entry-riders-refreshed',{detail:{data,select,personId:123}}));});
  assert.equal(await rider.inputValue(),'');assert.equal(await notice.innerText(),'This rider is already entered for this event.');
  await rider.selectOption('810001');assert.equal(await notice.isVisible(),false);
  assert.deepEqual(errors,[]);await p.close();
 }
 console.log('PASS: desktop/mobile disabled and labelled entered riders, exact warning, submit guard, email-first duplicate rejection, separate advisory horse warning and eligible rider selection.');
}finally{await b.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
