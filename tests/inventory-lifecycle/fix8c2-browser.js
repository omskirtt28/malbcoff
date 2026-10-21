async (page) => {
  page.setDefaultTimeout(10000);
  const base='http://127.0.0.1:8879';
  const context=page.context(); let csrf=''; let checks=0;
  const evidence=[];
  const ok=(v,m)=>{if(!v)throw new Error(m);checks++;evidence.push('PASS '+m);};
  // Fixture IDs are supplied by the isolated router's manifest route.
  const f=await (await context.request.get(base+'/__fixture-data')).json();
  const role=async(r,b)=>{await page.goto(base+'/__fixture?role='+r+(b?'&branch='+b:''));csrf=JSON.parse(await page.locator('body').innerText()).csrf;};
  const post=async(body,key)=>{const r=await context.request.post(base+'/actions/system_admin_deleted_records.php',{headers:{Accept:'application/json',...(key?{'X-Idempotency-Key':key}:{})},data:{_csrf:csrf,...body}});const text=await r.text();let data;try{data=JSON.parse(text);}catch{throw new Error('NonJSON '+body.action+' '+r.status()+' '+r.url()+' '+text.slice(0,300));}return {status:r.status(),...data};};
  const state=async()=>await(await context.request.get(base+'/__fixture-state')).json();
  const before=await state();
  for(const r of ['owner','branch_manager','inventory','cashier']){await role(r);for(const action of ['purge_units','preview_no_sales','purge_all_no_sales'])ok((await post({action,unit_ids:[f.units.single],confirmed:true,confirmation:'DELETE'})).status===403,`${r} denied ${action}`);}
  await role('system_admin');
  ok((await post({_csrf:'invalid',action:'preview_no_sales'})).status===419,'preview CSRF');
  ok((await post({_csrf:'invalid',action:'purge_units',unit_ids:[f.units.single],confirmed:true})).status===419,'delete CSRF');
  ok((await post({action:'purge_units',unit_ids:[f.units.bulk1,f.units.bulk2],confirmed:true})).status===422,'server requires DELETE for selected bulk');
  const stale=await post({action:'preview_no_sales'});
  await page.goto(base+'/system-admin-deleted-records?unit_q=Fix8C2');
  ok(await page.locator('[data-unit-select]').count()===7,'all seven no-sales fixtures selectable including old archives');
  ok(await page.locator(`[data-purge-unit="${f.units.bulk1}"]`).count()===1,'previous archived no-sales row has delete button');
  ok(await page.locator(`[data-unit-select][value="${f.units.sale}"]`).count()===0,'sales archive has no checkbox');
  ok(await page.getByText('This unit has sales history, so it is kept for records. Its IMEI can be used again.',{exact:true}).count()===1,'archived sales reason');
  for(const width of [360,768,1280]) {
    await page.setViewportSize({width,height:900});
    await page.locator('#deleted-inventory-units').scrollIntoViewIfNeeded();
    const m=await page.locator('#deleted-inventory-units').evaluate(el=>({overflow:document.documentElement.scrollWidth>innerWidth,labels:[...el.querySelectorAll('.cleanup-reference-grid span')].every(n=>getComputedStyle(n).whiteSpace==='nowrap'),grid:[...el.querySelectorAll('.cleanup-reference-grid')].every(n=>getComputedStyle(n).gridTemplateColumns.split(' ').length===2),targets:[...el.querySelectorAll('button,a,input:not([type=checkbox]),select,label:has(input[type=checkbox])')].filter(n=>n.getClientRects().length).every(n=>n.getBoundingClientRect().height>=44)}));
    ok(!m.overflow,'no horizontal page overflow '+width);ok(m.labels&&m.grid,'compact no-wrap 2x2 reference pills '+width);ok(m.targets,'44px controls '+width);
    await page.screenshot({path:`output/playwright/fix8c2-cleanup-${width}.png`,fullPage:true});
  }
  await page.locator(`[data-purge-unit="${f.units.single}"]`).click();
  ok(await page.locator('[data-unit-purge-dialog]').evaluate(el=>el.open),'single dialog opens');
  await page.locator('[data-purge-confirm]').click();
  await page.locator('[data-purge-status]').getByText('Saved ✓',{exact:true}).waitFor();
  ok(!(await state()).units.some(u=>Number(u.id)===f.units.single),'single physically removed');
  // Reload dismisses the already completed confirmation before the next independent action.
  await page.reload();
  await page.locator(`[data-unit-select][value="${f.units.bulk1}"]`).check();
  await page.locator(`[data-unit-select][value="${f.units.bulk2}"]`).check();
  await page.locator('[data-purge-selected]').click();
  ok(await page.locator('[data-purge-confirm]').isDisabled(),'selected bulk initially disabled');
  await page.locator('[data-purge-type]').fill('DELETE');await page.locator('[data-purge-confirm]').click();await page.locator('[data-purge-status]').getByText('Saved ✓',{exact:true}).waitFor();
  const selected=await state();ok(!selected.units.some(u=>[f.units.bulk1,f.units.bulk2].includes(Number(u.id))),'selected bulk removes both deleted and old archived units');
  let r=await post({action:'purge_all_no_sales',confirmed:true,confirmation:'DELETE',preview_token:stale.preview_token,preview_count:stale.count});ok(r.status===422,'stale preview refused');
  r=await post({action:'purge_units',unit_ids:[f.units.all1,f.units.available],confirmed:true,confirmation:'DELETE'});ok(r.status===422,'mixed available/deleted transaction refused');
  let s=await state();ok(s.units.some(u=>Number(u.id)===f.units.all1)&&s.audits.length===selected.audits.length,'mixed transaction rolls back unit, snapshots and audit');
  await page.reload();await page.locator('[data-unit-select-all]').check();ok(await page.locator('[data-unit-select]:checked').count()===4,'select all includes remaining no-sales rows');
  await page.locator('[data-purge-selected]').click();ok(await page.locator('[data-purge-type]').isVisible(),'select all requires typed confirmation');await page.locator('[data-purge-cancel]').click();
  await page.locator('[data-purge-all-no-sales]').click();
  await page.locator('[data-unit-purge-dialog][open]').waitFor();
  ok((await page.locator('[data-purge-count]').innerText()).includes('across all branches and pages'),'all-no-sales preview states global scope');
  ok(await page.locator('[data-purge-confirm]').isDisabled(),'delete all requires DELETE');
  await page.locator('[data-purge-type]').fill('DELETE');await page.locator('[data-purge-confirm]').click();await page.locator('[data-purge-status]').getByText('Saved ✓',{exact:true}).waitFor();
  s=await state();
  const survivors=['sale','void_only','available'].map(k=>f.units[k]);
  ok(s.units.length===3&&s.units.every(u=>survivors.includes(Number(u.id))),'all-no-sales keeps only sales, void and live fixture units');
  ok(JSON.stringify(s.buckets)===JSON.stringify(before.buckets),'every product/branch/day/movement bucket unchanged');
  ok(JSON.stringify(s.totals)===JSON.stringify(before.totals),'Stock Monitoring totals unchanged in all branch scopes');
  ok(JSON.stringify(s.transfers)===JSON.stringify(before.transfers),'all transfer quantities, statuses, times unchanged including pending');
  ok(s.dashboardLive===before.dashboardLive,'Dashboard available count unchanged');
  ok(JSON.stringify(s.saleItems)===JSON.stringify(before.saleItems)&&JSON.stringify(s.sale)===JSON.stringify(before.sale)&&JSON.stringify(s.voids)===JSON.stringify(before.voids),'sales and void history unchanged');
  for(const k of ['single','bulk1','bulk2','all1','all2','all3','movement_only']) {
    const audit=s.audits.find(a=>Number(a.unit_id)===f.units[k]&&a.operation==='permanent_delete');ok(!!audit,'required audit '+k);
    const a=JSON.parse(audit.snapshot_json);ok(a.unit.product_label&&a.unit.branch_name&&a.unit.permanently_deleted_by_name&&a.unit.permanently_deleted_at&&a.unit.deleted_by_name&&a.details.movement_rows_detached>0&&a.details.movement_rows_removed===0,'audit snapshot contents '+k);
    ok(s.movements.filter(m=>Number(m.deleted_unit_id)===f.units[k]).every(m=>m.unit_id===null&&JSON.parse(m.unit_snapshot).imei===f.identifiers[k]),'movement snapshots '+k);
  }
  await role('owner');
  for(const k of ['single','bulk1','bulk2','all1','all2','all3']) {
    const response=await context.request.get(base+'/actions/transfer_details.php?transfer_id='+f.transfers[k]);ok(response.status()===200,'transfer opens '+k);const d=await response.json();ok(d.units.length===1&&d.units[0].unit_deleted&&d.units[0].imei===f.identifiers[k]&&d.transfer.quantity===1,'transfer snapshot and quantity '+k);
  }
  await role('system_admin');
  r=await post({action:'purge_units',unit_ids:[f.units.void_only],confirmed:true});ok(r.archived===1,'void-only unit archives');
  const key='8c200001-0000-4000-8000-000000000001';
  r=await post({action:'purge_units',unit_ids:[f.units.single],confirmed:true},key);ok(r.already_processed===1,'already deleted harmless');r=await post({action:'purge_units',unit_ids:[f.units.single],confirmed:true},key);ok(r.replayed,'UUID replay');
  const preview=await post({action:'preview_no_sales'});ok(preview.count===0,'delete all can be rerun with zero eligible');
  r=await post({action:'purge_all_no_sales',confirmed:true,confirmation:'DELETE',preview_token:preview.preview_token,preview_count:0});ok(r.success&&r.deleted===0,'confirmed empty cleanup is harmless');
  await page.goto(base+'/system-admin-deleted-records?unit_q=Fix8C2');ok((await page.locator('.system-cleanup-audit').innerText()).includes('Transfer lines detached: 1'),'audit log displays required detachment counts');
  await role('owner');await page.goto(base+'/sales-records?sale='+f.sale);ok((await page.locator('body').innerText()).includes('FIX8C2-SALE-'),'original sale opens');
  await page.goto(base+'/stock-movement');ok((await page.locator('body').innerText()).includes('Unit deleted'),'Stock Movement labels snapshots');
  for(const k of ['stock_in','transfer']) {const response=await context.request.get(base+'/actions/stock_monitoring_drawer.php?kind='+k+'&q=Fix8C2');ok(response.status()===200,'drawer loads '+k);const d=await response.json();ok(JSON.stringify(d).includes('Unit deleted'),'drawer preserves deleted identifier '+k);}
  for(const width of [360,768,1280]) {await page.setViewportSize({width,height:900});await page.goto(base+'/branch-transfers?open='+f.transfers.single);await page.getByText('Unit deleted',{exact:true}).waitFor();ok(await page.getByText(f.identifiers.single,{exact:false}).count()>0,'Branch Transfers snapshot visible '+width);ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Branch Transfers no page overflow '+width);await page.screenshot({path:`output/playwright/fix8c2-transfer-${width}.png`,fullPage:true});}
  for(const [i,k] of ['single','bulk1','all1'].entries()) {
    await role('branch_manager',f.branches[i+1]);
    const scan=await context.request.get(base+'/actions/stock_in.php?check_identifier='+f.identifiers[k]);ok(!(await scan.json()).exists,'deleted IMEI scanner free at branch '+f.branches[i+1]);
    const receive=await context.request.post(base+'/actions/stock_in.php',{headers:{Accept:'application/json','X-Outbox-Send':'1','X-Idempotency-Key':`8c200002-0000-4000-8000-00000000000${i}`},form:{_csrf:csrf,confirmed:1,product_id:f.product,quantity:1,'identifiers[0]':f.identifiers[k],'identifier_types[0]':'imei'}});const d=await receive.json();ok(receive.status()===200&&d.ok,'same IMEI received at branch '+f.branches[i+1]+' '+JSON.stringify(d));
  }
  return {checks,evidence};
}
