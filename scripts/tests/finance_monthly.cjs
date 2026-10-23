// Uses the actual PHP view, an isolated browser and mocked balance requests.
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {execFileSync} = require('child_process');
const path = require('path');
const fs = require('fs');
const root = path.resolve(__dirname, '../..');
const php = `
require getcwd().'/helpers.php';
require getcwd().'/finance_monthly.php';
$_SESSION=[];
$_GET=['month'=>'2026-10','include_collected'=>getenv('ALL_MONTHS')?:''];
$canCreatePayout=getenv('VIEW_ONLY')!=='1';$stripeIsTest=getenv('LIVE_MODE')!=='1';
$monthlyReports=[];
foreach(['2026-10'=>5000,'2026-09'=>-100,'2026-08'=>0,'2026-07'=>1000] as $month=>$remaining){
$r=finance_month_empty($month);$r['payments']=$r['membership']=$r['net']=5000;$r['remaining']=$remaining;$r['collected']=5000-$remaining;
if($month==='2026-07')$r['missing_fees']=1;
$monthlyReports[$month]=$r;
}
$monthlyReports['2026-10']['transactions'][]=['id'=>1,'created_at'=>'2026-10-15 12:00:00','report_kind'=>'Payment','reference'=>'BOOK-1','person_name'=>'<script>window.injected=1</script>','allocated_amount'=>5000,'allocated_fee'=>100,'fee_known'=>true];
$collectionScope=getenv('COLLECTION_SCOPE')?:'memberships_logbooks';
$collectionTab=match($collectionScope){'events'=>'events','miscellaneous'=>'miscellaneous',default=>'memberships'};
$collectionTitle=match($collectionScope){'events'=>'Events','miscellaneous'=>'Miscellaneous',default=>'Memberships & Logbooks'};
if($collectionScope==='events'){$monthlyReports=[36=>$monthlyReports['2026-10']];$monthlyReports[36]['label']='Shanes Castle';$_GET['month']='36';}

echo '<!doctype html><meta charset="utf-8"><main class="admin-content">';
require getcwd().'/admin/finance_memberships.php';
echo '</main>';
`;
const render = env => execFileSync('php', ['-r',php], {cwd:root,env:{...process.env,...env},encoding:'utf8'});
const check=(value,message)=>{if(!value)throw Error(message);};
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.CHROMIUM_EXECUTABLE_PATH}:{})});
 try {
 const page=await browser.newPage();
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 let html=render({});let unavailable=false;
 await page.route('http://monthly.test/**',route=>{
  if(route.request().url().includes('stripe_balance'))return route.fulfill({contentType:'application/json',body:JSON.stringify(unavailable?{ok:false,error:'Balance unavailable'}:{ok:true,available:30,balance:70})});
  return route.fulfill({contentType:'text/html',body:html});
 });
 await page.goto('http://monthly.test/admin/finance.php?tab=memberships&month=2026-10');
 await page.waitForFunction(()=>document.querySelector('#monthly-stripe-summary').textContent.includes('£30.00'));
 check(!(await page.locator('tbody').first().textContent()).includes('August 2026'),'Fully collected month visible by default');
 check((await page.locator('tbody').first().textContent()).includes('-£1.00'),'Shortfall missing');
 check(await page.locator('button[data-month="2026-07"]').isDisabled(),'Missing-fee month is collectable');
 check(!(await page.evaluate(()=>window.injected)),'Account holder HTML was executed');
 // Dispatch Bootstrap's actual event contract (relatedTarget is the opening button).
 await page.evaluate(()=>{
  const e=new Event('show.bs.modal');Object.defineProperty(e,'relatedTarget',{value:document.querySelector('button[data-month="2026-10"]')});
  document.getElementById('collectMonthModal').dispatchEvent(e);
 });
 await page.waitForFunction(()=>document.getElementById('collect-month-amount').value==='30.00');
 check(await page.locator('#collect-month-amount').getAttribute('max')==='30.00','Maximum ignored Stripe limit');
 check(await page.locator('#collect-month').inputValue()==='2026-10','Month not submitted');
 await page.locator('#collect-month-amount').fill('10.00');
 check(await page.locator('#collect-month-amount').evaluate(el=>el.checkValidity()),'Partial collection invalid');
 await page.locator('#collect-month-amount').fill('30.01');
 check(!(await page.locator('#collect-month-amount').evaluate(el=>el.checkValidity())),'Overcollection valid in browser');
 unavailable=true;
 await page.evaluate(()=>{
  const e=new Event('show.bs.modal');Object.defineProperty(e,'relatedTarget',{value:document.querySelector('button[data-month="2026-10"]')});document.getElementById('collectMonthModal').dispatchEvent(e);
 });
 await page.waitForFunction(()=>document.getElementById('collect-month-balance').textContent==='Balance unavailable');
 check(await page.locator('#collect-month-submit').isDisabled(),'Failed balance lookup left Collect enabled');
 html=render({ALL_MONTHS:'1'});await page.reload();
 check((await page.locator('tbody').first().textContent()).includes('August 2026'),'Include fully collected does not work');
 html=render({VIEW_ONLY:'1'});await page.reload();
 check(await page.locator('button[data-month]').count()===0,'Read-only user sees collection controls');
 html=render({LIVE_MODE:'1'});await page.reload();
 check(await page.locator('button[data-month="2026-10"]').isDisabled(),'Live-mode Collect enabled');
 // Ensure the shared admin table controls can enhance these tables without losing forms or detail.
 await page.addScriptTag({content:fs.readFileSync(path.join(root,'admin/table_controls.js'),'utf8')});
 check(await page.locator('#month-detail').count()===1,'Detail disappeared after table enhancement');
 html=render({COLLECTION_SCOPE:'events'});await page.reload();
 check(await page.locator('button[data-month="36"]').count()===1,'Event group not rendered');
 check((await page.locator('#month-detail').textContent()).includes('Shanes Castle'),'Event label missing');
 check(await page.locator('#month-detail a[href="finance_event.php?event_id=36"]').count()===1,'Event drilldown missing');
 check(await page.locator('#collectMonthModal input[name=collection_scope]').inputValue()==='events','Event POST scope incorrect');
 html=render({COLLECTION_SCOPE:'miscellaneous'});await page.reload();
 check(await page.locator('#collectMonthModal input[name=collection_scope]').inputValue()==='miscellaneous','Miscellaneous POST scope incorrect');
 check(await page.locator('#collectMonthModal form').getAttribute('action')==='stripe_fulfilment.php','Collection still posts to Finance');
 check(errors.length===0,'Browser errors: '+errors.join('; '));
 console.log('Stripe Fulfilment browser checks: month visibility, shortfalls, missing fees, escaping, partial amounts, Stripe limits, unavailable balances, permissions and live-mode restriction passed.');
 } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
