async page => {
 const base='http://127.0.0.1:8879',c=page.context(),out=[];const f=await(await c.request.get(base+'/__fixture-data')).json();
 const ok=(v,m)=>{if(!v)throw new Error(m);out.push(m);};
 await page.goto(base+'/__fixture?role=system_admin');
 for(const width of [360,768,1280]) {
  await page.setViewportSize({width,height:900});await page.goto(base+'/system-admin-deleted-records?unit_q=Fix8C2');
  ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'cleanup no page overflow '+width);
  const m=await page.locator('#deleted-inventory-units').evaluate(el=>[...el.querySelectorAll('.cleanup-reference-grid span,.cleanup-reference-grid b,.table-subtext')].every(n=>parseFloat(getComputedStyle(n).fontSize)>=14));ok(m,'cleanup metadata/counts >=14px '+width);
  ok((await page.locator('#deleted-inventory-units').innerText()).includes('This unit has sales history, so it is kept for records. Its IMEI can be used again.'),'sales archive reason '+width);
  await page.locator('#deleted-inventory-units').screenshot({path:`output/playwright/fix8c2-final-cleanup-${width}.png`});
 }
 await page.goto(base+'/__fixture?role=owner');
 for(const width of [360,768,1280]) {
  await page.setViewportSize({width,height:900});await page.goto(base+'/branch-transfers?open='+f.transfers.single);await page.getByText('Unit deleted',{exact:true}).waitFor();
  ok(await page.locator('.transfer-proof-unit.is-deleted .status-pill').evaluate(el=>parseFloat(getComputedStyle(el).fontSize)>=14),'transfer snapshot label >=14px '+width);
  ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'transfer no page overflow '+width);
  await page.screenshot({path:`output/playwright/fix8c2-transfer-${width}.png`});
 }
 await page.goto(base+'/stock-movement');ok(await page.locator('.inventory-deleted-identifier').first().evaluate(el=>parseFloat(getComputedStyle(el).fontSize)>=14),'Stock Movement snapshot >=14px');
 for(const path of ['/dashboard','/stock-monitoring','/sales-records?sale='+f.sale]) {await page.goto(base+path);ok(!/Warning:|Fatal error:|could not be loaded/i.test(await page.locator('body').innerText()),'final page loads '+path);}
 return {checks:out.length,evidence:out};
}
