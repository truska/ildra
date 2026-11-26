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
  await p.locator('#prefillPerson').selectOption('123');
  assert.equal(await notice.innerText(),'This rider is already entered in this event.');
  await p.locator('#prefillHorse').selectOption('810001');
  assert.equal(await notice.innerText(),'This rider is already entered in this event. This horse is already entered in this event.');
  await p.locator('#rideTypeSelect').selectOption('PR');
  assert.equal(await p.locator('#classSelect option[value="810100"]').isDisabled(),false);
  await p.locator('#classSelect').selectOption('810100');assert.equal(await p.locator('#submitEntry').isEnabled(),true);
  assert.equal((await p.locator('#classPriceHint').innerText()).includes('Member rates unlocked'),true);
  await p.locator('#horseSelectionToggle').uncheck();assert.equal(await notice.innerText(),'This rider is already entered in this event.');
  await p.locator('#prefillPerson').selectOption('810001');assert.equal(await notice.isVisible(),false);
  assert.deepEqual(errors,[]);await p.close();
 }
 console.log('PASS: desktop/mobile rider and horse warnings, member price remains selectable, Enter Event enabled, placeholder excluded and stale warnings cleared.');
}finally{await b.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
