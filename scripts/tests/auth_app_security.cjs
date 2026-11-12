const {chromium}=require('playwright');
const {execFileSync}=require('child_process');
const assert=require('node:assert/strict');
const render=(enabled,pending)=>execFileSync('php',['-r',`
 require 'helpers.php';require 'auth_app_security.php';$_SESSION=[];
 $source=file_get_contents('account.php');
 $heading=strpos($source,'Confirmed <?php echo h(format_display_date');
 $start=strrpos(substr($source,0,$heading),'<?php if (');
 $end=strpos($source,'recognition',$start);
 $end=strrpos(substr($source,0,$end),'<?php elseif');
 $fragment=substr($source,$start,$end-$start);
 $authAppStatus=['enabled'=>${enabled?'true':'false'},'confirmed_at'=>'2026-11-12'];
 $authAppSetup=${pending?"['formatted_secret'=>'FIXT UREK EYON LY','qr_data_uri'=>'']":'null'};
 ob_start();eval('?>'.$fragment);$html=ob_get_clean();echo csrf_protect_forms($html);
`],{encoding:'utf8'});
(async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  const page=await browser.newPage();page.on('dialog',dialog=>dialog.accept());
  const fixture=async(enabled,pending=false)=>{
   await page.setContent(render(enabled,pending));
   await page.evaluate(()=>{window.posts=[];document.addEventListener('submit',event=>{event.preventDefault();posts.push(Object.fromEntries(new FormData(event.target,event.submitter)))})});
  };
  await fixture(false);
  assert.equal(await page.locator('[name=auth_password]').count(),1);
  assert.equal(await page.locator('[name=auth_existing_code]').count(),0);
  await page.locator('[name=auth_password]').fill('fixture proof');
  await page.getByRole('button',{name:'Verify and Set Up Authenticator App',exact:true}).click();
  let data=await page.evaluate(()=>posts[0]);assert.equal(data.action,'auth_app_begin_setup');assert.equal(data.auth_password,'fixture proof');assert.ok(data.csrf);
  await fixture(true);
  assert.equal(await page.getByRole('button',{name:'Replace Authenticator App',exact:true}).count(),1);
  await page.locator('[name=auth_existing_code]').fill('123456');
  await page.getByRole('button',{name:'Verify and Disable Authenticator App',exact:true}).click();
  data=await page.evaluate(()=>posts[0]);assert.equal(data.action,'auth_app_disable');assert.equal(data.auth_existing_code,'123456');assert.ok(data.csrf);
  for(const enabled of [false,true]){
   await fixture(enabled,true);
   assert.equal(await page.locator('[name=auth_password]').count(),0);
   assert.equal(await page.locator('[name=code]').count(),1);
   assert.match(await page.locator('body').textContent(),/within 10 minutes/);
   if(enabled)assert.match(await page.locator('body').textContent(),/existing authenticator stays active/);
   await page.locator('[name=code]').fill('654321');await page.getByRole('button',{name:'Verify and Enable',exact:true}).click();
   data=await page.evaluate(()=>posts[0]);assert.equal(data.action,'auth_app_confirm_setup');assert.equal(data.code,'654321');assert.ok(data.csrf);
  }
  console.log('Authenticator account forms passed: fresh proof inputs, setup/replace/remove action values, replacement guidance, new-code confirmation and CSRF. Offline browser fixtures only.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1)});
