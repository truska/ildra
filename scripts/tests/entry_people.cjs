const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
(async()=>{
 const directory=process.argv[2];if(!directory||!fs.realpathSync(directory).startsWith('/tmp/'))throw new Error('Pass private /tmp fixtures');
 const before=fs.readFileSync(directory+'/entry-person-before.html','utf8'),after=fs.readFileSync(directory+'/entry-person-after.html','utf8');
 const bootstrap=fs.readFileSync('/tmp/ildra-bootstrap-5.3.2.bundle.min.js'),css=fs.readFileSync('/tmp/ildra-bootstrap-5.3.2.min.css');
 const browser=await chromium.launch({headless:true});
 try {
  for(const [width,height] of [[1440,800],[390,640],[844,390]]) {
   const page=await browser.newPage({viewport:{width,height}});const errors=[];page.on('pageerror',e=>{errors.push(e.message);console.error('Page script:',e.message);});
   let reads=0,posts=0,scenario='existing',detailsPosts=0;
   await page.route('**/*',route=>{
    const request=route.request(),url=request.url();
    if(url.includes('bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js'))return route.fulfill({contentType:'application/javascript',body:bootstrap,headers:{'Access-Control-Allow-Origin':'*'}});
    if(url.includes('bootstrap@5.3.2/dist/css/bootstrap.min.css'))return route.fulfill({contentType:'text/css',body:css,headers:{'Access-Control-Allow-Origin':'*'}});
    if(url.endsWith('/assets/entry_horses.js'))return route.fulfill({contentType:'application/javascript',body:fs.readFileSync('assets/entry_horses.js')});
    if(url.endsWith('/assets/entry_person.js'))return route.fulfill({contentType:'application/javascript',body:fs.readFileSync('assets/entry_person.js')});
    if(url.includes('/entry_person.php')) {
     posts++;const body=request.postData()||'';assert.match(body,/name="csrf"/);
     let result;
     if(scenario==='existing')result={status:'ready',person_id:790001};
     else if(body.includes('name="stage"\r\n\r\ndetails')) {
      detailsPosts++;result=detailsPosts===1?{status:'error',errors:{phone:'Phone number is required.',dob:'All Riders need to be 5 years old or over.'}}:{status:'ready',person_id:790001};
     }else result={status:'details',email:'new@example.invalid'};
     return route.fulfill({contentType:'application/json',body:JSON.stringify(result)});
    }
    if(url.includes('/event.php'))return route.fulfill({contentType:'text/html',body:reads++===0?before:after});
    return route.abort();
   });
   await page.goto('http://entry.test/event.php?id=790001');
   const rider=page.locator('#prefillPerson');
   assert.equal(await rider.locator('option').last().textContent(),'Another Person');
   assert.equal(await page.getByRole('link',{name:'Manage People',exact:true}).count(),0);
   await rider.selectOption('123');assert.equal(await page.locator('#prefillHorse').inputValue(),'1');
   await page.locator('#contactPhone').fill('keep this phone');
   await rider.selectOption('another');await page.waitForSelector('#entryPersonModal.show');
   assert.equal(await rider.inputValue(),'123');assert.equal(await page.locator('#entryPersonDetails').isVisible(),false);
   await page.locator('#entryPersonModal').getByRole('button',{name:'Cancel',exact:true}).click();
   await page.waitForSelector('#entryPersonModal',{state:'hidden'});assert.equal(posts,0);
   await rider.selectOption('another');await page.waitForSelector('#entryPersonModal.show');
   await page.locator('#entryPersonEmail').fill('selected@example.invalid');
   await page.locator('#entryPersonSubmit').click();await page.waitForSelector('#entryPersonModal',{state:'hidden'});
   assert.equal(await rider.inputValue(),'790001');assert.equal(await page.locator('#personId').inputValue(),'790001');
   assert.equal(await page.locator('#riderName').inputValue(),'Selected Rider');
   assert.equal(await page.locator('#prefillHorse').inputValue(),'1');assert.equal(await page.locator('#contactPhone').inputValue(),'keep this phone');
   assert.equal(await rider.locator('option[value="790001"]').count(),1);assert.equal(page.url(),'http://entry.test/event.php?id=790001');
   scenario='new';await rider.selectOption('another');await page.waitForSelector('#entryPersonModal.show');
   await page.locator('#entryPersonEmail').fill('new@example.invalid');await page.locator('#entryPersonSubmit').click();
   await page.waitForFunction(()=>!document.getElementById('entryPersonDetails').hidden);
   await page.locator('#entryPerson_first_name').fill('New');await page.locator('#entryPerson_last_name').fill('Rider');
   await page.locator('#entryPersonSubmit').click();await page.waitForSelector('#entryPerson_phone.is-invalid');
   assert.equal(await page.locator('#entryPerson_phone').getAttribute('aria-invalid'),'true');
   assert.equal(await page.locator('#entryPerson_phone_error').textContent(),'Phone number is required.');
   assert.equal(await page.locator('#entryPerson_first_name').inputValue(),'New');
   const footer=page.locator('#entryPersonForm .modal-footer');
   const footerBox=await footer.boundingBox();assert.ok(footerBox.y>=0 && footerBox.y+footerBox.height<=height,'Action buttons escaped the viewport');
   assert.equal(await page.locator('#entryPersonForm .modal-body').evaluate(el=>getComputedStyle(el).overflowY),'auto');
   if(height<=640) {
    assert.ok(await page.locator('#entryPersonForm .modal-body').evaluate(el=>el.scrollHeight>el.clientHeight),'Small screen form body does not scroll');
    await page.locator('#entryPersonDob').scrollIntoViewIfNeeded();
    const afterScroll=await footer.boundingBox();assert.ok(afterScroll.y+afterScroll.height<=height,'Footer moved off screen while scrolling');
    assert.equal(await page.locator('#entryPersonDob_error').textContent(),'All Riders need to be 5 years old or over.');
   }

   await page.locator('#entryPerson_phone').fill('0123456789');await page.locator('#entryPersonSubmit').click();
   await page.waitForSelector('#entryPersonModal',{state:'hidden'});
   assert.equal(await page.locator('#contactPhone').inputValue(),'keep this phone');assert.equal(await rider.inputValue(),'790001');
   assert.deepEqual(errors,[]);await page.close();
  }
  console.log('PASS: Another Person email-first modal, cancel, existing/new flows, field errors, rider refresh/selection and retained horse/contact values at desktop/mobile widths.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
