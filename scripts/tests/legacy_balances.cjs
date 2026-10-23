// Generated account notice HTML only: no database, email delivery or real account changes.
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {execFileSync}=require('child_process');
const path=require('path');
const root=path.resolve(__dirname,'../..');
const php=`require getcwd().'/helpers.php';require getcwd().'/legacy_balances.php';$_SESSION=[];$currentUser=['id'=>7];$legacyBalancePending=getenv('PENDING')==='1';$legacyBalanceNotifications=$legacyBalancePending?[]:[['id'=>15,'opening_amount'=>'25.00'],['id'=>16,'opening_amount'=>'-17.00']];if(getenv('IMPERSONATING')==='1')$_SESSION['act_as_original_user']=['id'=>9];if(getenv('LOGGED_OUT')==='1')$currentUser=null;echo '<!doctype html><meta charset="utf-8">';require getcwd().'/views/legacy_balance_notice.php';`;
const render=env=>execFileSync('php',['-r',php],{cwd:root,env:{...process.env,...env},encoding:'utf8'});
const check=(ok,message)=>{if(!ok)throw Error(message);};
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.CHROMIUM_EXECUTABLE_PATH}:{})});
 try{
  const page=await browser.newPage();let html=render({});let post='';
  await page.route('http://legacy.test/**',route=>{if(route.request().method()==='POST')post=route.request().postData();return route.fulfill({contentType:'text/html; charset=utf-8',body:html});});
  await page.goto('http://legacy.test/account');
  const modal=page.locator('#legacyBalanceModal');
  await modal.evaluate(element=>element.removeAttribute('aria-hidden')); // Bootstrap removes it when opening the modal.
  check((await modal.textContent()).includes('£25.00 credit added'),'Credit missing from notice');
  check((await modal.textContent()).includes('£17.00 debit carried forward'),'Debit missing from notice');
  check(await modal.locator('input[name=balance_ids]').inputValue()==='15,16','Acknowledgement must name displayed rows');
  check((await modal.locator('input[name=csrf]').inputValue()).length===64,'CSRF token missing');
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Got it',exact:true}).click()]);
  const submitted=new URLSearchParams(post);
  check(submitted.get('action')==='legacy_balance_acknowledge'&&submitted.get('balance_ids')==='15,16','Acknowledgement POST incorrect');
  html=render({PENDING:'1'});await page.reload();
  check(await page.locator('#legacyBalanceModal').count()===0,'Pending balance appears already allocated');
  check((await page.locator('body').textContent()).includes('confirm ownership'),'Email confirmation explanation missing');
  check(!(await page.locator('body').textContent()).includes('£25'),'Pending amount disclosed before confirmation');
  check(await page.getByRole('button',{name:'Send confirmation email'}).count()===1,'Confirmation action missing');
  html=render({IMPERSONATING:'1'});await page.reload();
  check(await page.locator('form').count()===0,'Impersonation exposes claim actions');
  html=render({LOGGED_OUT:'1'});await page.reload();
  check(await page.locator('form').count()===0,'Anonymous visitor sees claim actions');
  console.log('Opening balance browser checks: credit/debit notices, row-specific acknowledgement, CSRF, pending confirmation, anonymous and impersonation visibility passed.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
