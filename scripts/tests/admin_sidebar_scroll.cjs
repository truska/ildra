const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
(async()=>{
 const source=fs.readFileSync('admin/_bootstrap.php','utf8');
 const css=source.slice(source.indexOf('<style>')+7,source.indexOf('</style>'));
 const bodyAt=source.indexOf('const body = document.body;');
 const start=source.lastIndexOf('(function()',bodyAt),end=source.indexOf('})();',bodyAt)+5;
 const script=source.slice(start,end);
 const links=Array.from({length:60},(_,i)=>`<a class="nav-link" href="#item-${i}"><span class="admin-nav-icon">•</span><span class="admin-nav-label">Menu item ${i}</span></a>`).join('');
 const html=`<style>*{box-sizing:border-box}body{margin:0}.nav-link{padding:12px}.admin-content{height:3500px}${css}</style><div class="admin-mobilebar"><button data-admin-menu-toggle>Menu</button></div><div class="admin-sidebar-overlay" data-admin-menu-close></div><div class="admin-shell"><aside class="admin-sidebar" id="admin-sidebar"><div class="admin-sidebar-header"><button class="admin-sidebar-collapse" data-admin-collapse>Collapse</button><button class="admin-sidebar-close" data-admin-menu-close>Close</button></div><nav>${links}</nav></aside><main class="admin-content">Long editor</main></div><script>${script}</script>`;
 const browser=await chromium.launch({headless:true});try{
  for(const width of [1440,1024,768,390]){
   const context=await browser.newContext({viewport:{width,height:800}});const page=await context.newPage();
   await page.route('http://sidebar-fixture.invalid/**',route=>route.fulfill({body:html,contentType:'text/html'}));await page.goto('http://sidebar-fixture.invalid/');
   const sidebar=page.locator('#admin-sidebar');
   if(width>=992){
    await page.evaluate(()=>window.scrollTo(0,700));
    assert.ok(await page.evaluate(()=>window.scrollY>=700));
    let box=await sidebar.boundingBox();assert.ok(Math.abs(box.y)<1);assert.ok(Math.abs(box.height-800)<1);
    const before=await page.evaluate(()=>window.scrollY);await sidebar.hover();await page.mouse.wheel(0,450);await page.waitForFunction(()=>document.getElementById('admin-sidebar').scrollTop>0);
    assert.equal(await page.evaluate(()=>window.scrollY),before,'Menu scroll moved editor');
    await sidebar.evaluate(el=>el.scrollTop=el.scrollHeight);await page.mouse.wheel(0,500);await page.waitForTimeout(100);assert.equal(await page.evaluate(()=>window.scrollY),before,'Menu boundary scrolled editor');
    await sidebar.evaluate(el=>el.scrollTop=0);await page.locator('.admin-sidebar-collapse').click();await page.waitForTimeout(250);
    box=await sidebar.boundingBox();assert.ok(Math.abs(box.width-76)<1);assert.ok(Math.abs(box.y)<1);
   }else{
    assert.equal(await sidebar.evaluate(el=>getComputedStyle(el).position),'fixed');
    await page.locator('[data-admin-menu-toggle]').click();await page.waitForTimeout(250);
    assert.ok(await page.locator('body').evaluate(el=>el.classList.contains('admin-nav-open')));
    let box=await sidebar.boundingBox();assert.ok(Math.abs(box.x)<1);assert.ok(Math.abs(box.height-800)<1);
    await sidebar.hover();await page.mouse.wheel(0,450);await page.waitForFunction(()=>document.getElementById('admin-sidebar').scrollTop>0);
    assert.equal(await page.evaluate(()=>window.scrollY),0);
    await page.keyboard.press('Escape');assert.equal(await page.locator('body').evaluate(el=>el.classList.contains('admin-nav-open')),false);
    await page.evaluate(()=>window.scrollTo(0,600));assert.ok(await page.evaluate(()=>window.scrollY>=600));
   }
   await context.close();
  }
  console.log('PASS: sidebar remains at viewport top, scrolls independently without scroll chaining, collapse works, and mobile/iPad drawer behaviour is retained.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1)});
