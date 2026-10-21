const {chromium}=require('C:/Users/John Joshua/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs=require('fs');const {execFileSync}=require('child_process');const assert=require('assert/strict');
const f=JSON.parse(fs.readFileSync('tests/inventory-lifecycle/fixtures.json','utf8'));
const base='http://127.0.0.1:8878';let checks=0;
function ok(v,msg){assert(v,msg);checks++;console.log('PASS '+msg);}
function state(){return JSON.parse(execFileSync('C:/xampp/php/php.exe',['tests/inventory-lifecycle/state.php'],{encoding:'utf8'}));}
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});const context=await browser.newContext();const page=await context.newPage();page.setDefaultTimeout(15000);
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 let csrf='';
 async function role(r,b){await page.goto(base+'/__fixture?role='+r+(b?'&branch='+b:''));csrf=JSON.parse(await page.locator('body').innerText()).csrf;}
 async function post(path,body,form=false,key){const opts={headers:{Accept:'application/json',...(key?{'X-Idempotency-Key':key}:{})}};opts[form?'form':'data']={_csrf:csrf,...body};const r=await context.request.post(base+'/actions/'+path,opts);let j;try{j=await r.json();}catch{throw new Error('NonJSON '+r.status()+' '+(await r.text()).slice(0,600));}return {status:r.status(),...j};}
 await role('branch_manager');
 let r=await post('system_admin_deleted_records.php',{action:'purge_units',unit_ids:[f.units.no_refs],confirmed:true});ok(r.status===403,'branch direct admin cleanup denied');
 for(const rname of ['owner','inventory','cashier']){await role(rname);r=await post('system_admin_deleted_records.php',{action:'purge_units',unit_ids:[f.units.no_refs],confirmed:true});ok(r.status===403,rname+' direct admin cleanup denied');}
 await role('branch_manager');r=await post('delete_inventory_unit.php',{_csrf:'bad',confirmed:true,product_id:f.products.Delete,branch_id:f.b1,unit_ids:[f.units.plain]});ok(r.status===419,'branch deletion CSRF enforced');
 r=await post('delete_inventory_unit.php',{confirmed:true,product_id:f.products.Delete,branch_id:f.b2,unit_ids:[f.units.plain]});ok(r.status===403,'cross-branch deletion denied');
 const key='aa000001-0000-4000-8000-000000000001';
 r=await post('delete_inventory_unit.php',{confirmed:true,product_id:f.products.Delete,branch_id:f.b1,unit_ids:[f.units.plain,f.units.history]},false,key);ok(r.success,'branch soft deletion succeeds');
 r=await post('delete_inventory_unit.php',{confirmed:true,product_id:f.products.Delete,branch_id:f.b1,unit_ids:[f.units.plain,f.units.history]},false,key);ok(r.replayed,'soft delete retry replays result');
 let s=state();ok(s.units.filter(u=>[f.units.plain,f.units.history].includes(Number(u.id))).every(u=>u.status==='deleted'),'rows retained and marked deleted');
 ok(s.movements.filter(m=>m.reference_no==='REMOVE-'+f.units.plain).length===1,'exactly one removal movement after retry');
 ok(s.movements.filter(m=>Number(m.product_id)===f.products.Delete).reduce((n,m)=>n+Number(m.quantity),0)===0,'removed stock reconciles to zero');
 await page.goto(base+'/inventory');ok(!(await page.locator('body').innerText()).includes('FIX-plain'),'deleted identifier hidden from inventory');
 r=await post('complete_sale.php',{branch_id:f.b1,payment_method:'cash',amount_received:100,cart_json:JSON.stringify([{kind:'device',product_id:f.products.Delete,unit_id:f.units.plain,price_mode:'piece',price:100}])},true);ok(r.status>=400,'deleted unit cannot be sold');
 r=await post('forward_inventory.php',{product_id:f.products.Delete,destination_branch_id:f.b2,unit_ids:[f.units.plain],quantity:1});ok(r.status===422,'deleted unit cannot be transferred');
 let scan=await context.request.get(base+'/actions/stock_in.php?check_identifier=FIX-plain');ok((await scan.json()).exists,'soft delete keeps identifier reserved until admin cleanup');
 await role('system_admin');await page.goto(base+'/system-admin-deleted-records?unit_q=FIX-');ok((await page.locator('body').innerText()).includes('Deleted Inventory Units'),'admin unit section loads');
 r=await post('system_admin_deleted_records.php',{action:'purge_units',unit_ids:[f.units.plain,f.units.history],confirmed:true});ok(r.status===422,'bulk requires typed DELETE on server');
 r=await post('system_admin_deleted_records.php',{action:'purge_units',unit_ids:[f.units.history],confirmed:true});ok(r.archived===1,'unit with sale and transfer history archived');
 s=state();let history=s.units.find(u=>Number(u.id)===f.units.history);ok(history.status==='archived'&&!history.imei&&!history.imei2&&!history.serial_no&&history.archived_imei==='FIX-history','all archived identifiers released and original saved');
 ok(s.sales.some(si=>Number(si.unit_id)===f.units.history),'sale reference preserved');
 r=await post('system_admin_deleted_records.php',{action:'purge_units',unit_ids:[f.units.history],confirmed:true});ok(r.already_processed===1,'repeat purge is idempotent');
 r=await post('system_admin_deleted_records.php',{action:'purge_units',unit_ids:[f.units.no_refs],confirmed:true});ok(r.deleted===1,'unit without references removed physically');
 ok(!state().units.some(u=>Number(u.id)===f.units.no_refs),'unreferenced row no longer exists');
 // Single-unit confirmation dialog and shared save helper.
 await page.goto(base+'/system-admin-deleted-records?unit_q=FIX-plain');
 await page.locator('[data-purge-unit="'+f.units.plain+'"]').click();
 ok((await page.locator('[data-purge-count]').innerText()).includes('1 unit'),'single dialog lists count');
 await page.locator('[data-purge-confirm]').click();await page.locator('[data-purge-status] .save-status.is-success').waitFor();
 await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:'OK',exact:true}).click()]);
 ok((await page.locator('#deleted-inventory-units').innerText()).includes('Archived (history kept)'),'single UI purge archives movement history');
 // Select several, then all on the current page; page limit leaves a second page.
 await page.goto(base+'/system-admin-deleted-records?unit_q=FIX-bulk-');
 ok(await page.locator('[data-unit-select]').count()===25,'deleted units paginated at 25');
 for(let i=0;i<2;i++)await page.locator('[data-unit-select]').nth(i).check();
 await page.locator('[data-purge-selected]').click();ok(await page.locator('[data-purge-confirm]').isDisabled(),'bulk dialog blocks before typed DELETE');
 await page.locator('[data-purge-type]').fill('DELETE');await page.locator('[data-purge-confirm]').click();await page.locator('[data-purge-status] .save-status.is-success').waitFor();await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:'OK',exact:true}).click()]);
 await page.locator('[data-unit-select-all]').check();ok(await page.locator('[data-unit-select]:checked').count()===25,'select all selects only current page');
 await page.locator('[data-purge-selected]').click();ok((await page.locator('[data-purge-count]').innerText()).includes('25 unit'),'bulk dialog shows exact page count');
 await page.locator('[data-purge-type]').fill('DELETE');await page.locator('[data-purge-confirm]').click();await page.locator('[data-purge-status] .save-status.is-success').waitFor();await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:'OK',exact:true}).click()]);
 ok(await page.locator('[data-unit-select]').count()===3,'unselected other-page units preserved');
 // Identifiers released on all fields; receive again at another branch, via actual action.
 await role('branch_manager',f.b2);
 for(const id of ['FIX-plain','FIX-plain2','SN-FIX-plain','FIX-history','FIX-history2','SN-FIX-history']){scan=await context.request.get(base+'/actions/stock_in.php?check_identifier='+id);ok(!(await scan.json()).exists,'released identifier scans free: '+id);}
 r=await post('stock_in.php',{confirmed:'1',product_id:f.products.Delete,branch_id:f.b2,quantity:1,'identifiers[0]':'FIX-PLAIN','identifier_types[0]':'barcode'},true); // Retry below with reviewed outbox header if needed.
 if(r.status!==200){const rr=await context.request.post(base+'/actions/stock_in.php',{headers:{Accept:'application/json','X-Outbox-Send':'1','X-Idempotency-Key':'aa000001-0000-4000-8000-000000000002'},form:{_csrf:csrf,confirmed:'1',product_id:f.products.Delete,branch_id:f.b2,quantity:1,'identifiers[0]':'FIX-PLAIN','identifier_types[0]':'barcode'}});r={status:rr.status(),...await rr.json()};}
 ok(r.ok,'released identifier received in another branch: '+JSON.stringify(r));
 await role('branch_manager');
 r=await post('complete_sale.php',{branch_id:f.b1,payment_method:'cash',amount_received:100,cart_json:JSON.stringify([{kind:'device',product_id:f.products.SaleLast,unit_id:f.units.SaleLast,price_mode:'piece',price:100}])},true);ok(r.ok||r.success,'sell last unit: '+JSON.stringify(r));
 r=await post('forward_inventory.php',{product_id:f.products.TransferLast,destination_branch_id:f.b2,unit_ids:[f.units.TransferLast],quantity:1});ok(r.success,'transfer last unit');
 // Responsive branch/owner/matrix + filters, export and drawer totals.
 for(const view of ['branch','owner-branch','matrix','deleted']){
   await role(view==='deleted'?'system_admin':view==='branch'?'branch_manager':'owner');
   const url=view==='deleted'?'/system-admin-deleted-records?unit_q=FIX-':'/stock-monitoring?q=Lifecycle'+(view==='owner-branch'?'&branch='+f.b1:'');
   await page.goto(base+url);await page.waitForLoadState('domcontentloaded');
   ok(!(await page.locator('body').innerText()).includes('could not be loaded'),view+' loads');
   if(view!=='deleted'){
    const text=await page.locator('body').innerText();ok(text.includes('Lifecycle SaleLast')&&text.includes('Lifecycle TransferLast')&&text.includes('Lifecycle ZeroOld'),view+' retains zero models after sale / transfer');ok(!text.includes('Lifecycle Never')&&!text.includes('Lifecycle CatalogRemoved'),view+' excludes unstocked and deleted catalog models');
    ok(await page.locator('.stock-out-label').count()>0,view+' shows Out of stock label');
    await page.getByRole('link',{name:'Out of stock',exact:true}).click();await page.waitForLoadState('domcontentloaded');ok((await page.locator('.stock-monitoring-card').innerText()).includes('Lifecycle SaleLast'),view+' out-of-stock filter keeps sold model');
    await page.getByRole('link',{name:'In stock',exact:true}).click();await page.waitForLoadState('domcontentloaded');ok(!(await page.locator('.stock-monitoring-card').innerText()).includes('Lifecycle SaleLast'),view+' in-stock filter removes sold-out model');
    await page.getByRole('link',{name:'All',exact:true}).click();await page.waitForLoadState('domcontentloaded');
    const csv=await context.request.get(base+'/actions/export_stock_monitoring.php?format=csv&q=Lifecycle'+(view==='owner-branch'?'&branch='+f.b1:''));ok((await csv.text()).includes('Lifecycle ZeroOld'),view+' CSV includes zero-history row');
   }
   for(const width of [360,768,1280]){await page.setViewportSize({width,height:900});await page.waitForTimeout(100);const geom=await page.evaluate(()=>({w:innerWidth,scroll:document.documentElement.scrollWidth}));ok(geom.scroll<=geom.w,view+' no page overflow at '+width+' '+JSON.stringify(geom));await page.screenshot({path:`output/playwright/${view}-${width}.png`,fullPage:true});}
   if(view==='matrix'){
    for(const stock of ['all','out','in'])for(const kind of ['overall','stock_in','sold','transfer']){const resp=await context.request.get(base+'/actions/stock_monitoring_drawer.php?monitoring=1&q=Lifecycle&stock='+stock+'&kind='+kind);const data=await resp.json();ok(!data.error,'drawer loads '+kind+' '+stock);ok(data.total===data.groups.reduce((n,g)=>n+g.qty,0),'drawer group totals equal card total '+kind+' '+stock);}
   }
 }
 ok(errors.length===0,'no browser JS exceptions: '+errors.join('; '));
 fs.writeFileSync('output/playwright/checks.json',JSON.stringify({checks,errors},null,2));await browser.close();console.log('ALL '+checks+' CHECKS PASSED');
})().catch(e=>{console.error(e);process.exit(1)});
