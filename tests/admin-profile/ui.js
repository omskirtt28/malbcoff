async (page) => {
  page.setDefaultTimeout(10000);
  const base='http://127.0.0.1:8881', ctx=page.context(), evidence=[];
  const ok=(v,m)=>{if(!v)throw new Error(m);evidence.push('PASS '+m);};
  const login=async(role)=>{await page.goto(base+'/__test/login?role='+role);};
  const metrics=async(root)=>await page.locator(root).evaluate(el=>({
    overflow:document.documentElement.scrollWidth>innerWidth,
    targets:[...el.querySelectorAll('input:not([type=hidden]),select,button,summary')].filter(n=>n.getClientRects().length&&n.getBoundingClientRect().height>0).map(n=>({label:n.textContent||n.name,height:n.getBoundingClientRect().height})).filter(n=>n.height<44),
    text:[...el.querySelectorAll('label span,small,p,strong,td,.table-subtext,.status-pill')].filter(n=>n.getClientRects().length).map(n=>({label:n.textContent,size:parseFloat(getComputedStyle(n).fontSize)})).filter(n=>n.size<14),
  }));
  await login('system_admin'); await page.goto(base+'/system-admin-users');
  for(const width of [360,768,1280]) {
    await page.setViewportSize({width,height:900});
    let m=await metrics('.page-content');
    ok(!m.overflow,'admin page no horizontal overflow '+width);
    ok(m.targets.length===0,'admin page 44px controls '+width+' '+JSON.stringify(m.targets));
    ok(m.text.length===0,'admin page 14px text '+width+' '+JSON.stringify(m.text));
    await page.screenshot({path:`output/playwright/admin-profile-accounts-${width}.png`,fullPage:true});
    await page.getByRole('button',{name:'Add User',exact:true}).click();
    ok(await page.locator('#addUserDialog').evaluate(el=>el.open),'Add User dialog opens '+width);
    m=await metrics('#addUserDialog');
    ok(!m.overflow&&m.targets.length===0&&m.text.length===0,'Add User dialog sizing '+width);
    await page.screenshot({path:`output/playwright/admin-profile-add-${width}.png`});
    await page.keyboard.press('Escape');
    ok(await page.getByRole('button',{name:'Add User',exact:true}).evaluate(el=>el===document.activeElement),'dialog returns focus '+width);
    await page.getByRole('button',{name:'Manage Branches',exact:true}).click();
    await page.locator('#manageBranchesDialog summary').first().click();
    m=await metrics('#manageBranchesDialog');
    ok(!m.overflow&&m.targets.length===0&&m.text.length===0,'branch dialog sizing '+width);
    await page.screenshot({path:`output/playwright/admin-profile-branches-${width}.png`});
    await page.keyboard.press('Escape');
  }
  // Create via the actual SafeSubmit form.
  await page.getByRole('button',{name:'Add User',exact:true}).click();
  let dialog=page.locator('#addUserDialog');
  await dialog.locator('[name=role]').selectOption('owner');
  ok(await dialog.locator('[data-admin-all-branches]').isVisible()&&await dialog.locator('[name=branch_id]').isDisabled(),'Owner UI shows All Branches');
  await dialog.locator('[name=role]').selectOption('cashier');
  const fullName='UI <script> Account '+Date.now();
  await dialog.getByLabel('Full Name',{exact:true}).fill(fullName);
  const email=`ui-${Date.now()}@test.invalid`;
  await dialog.getByLabel('Email',{exact:true}).fill(email);
  await dialog.locator('[name=branch_id]').selectOption({index:1});
  await dialog.locator('[name=temporary_password]').fill('TemporaryPass123');
  await dialog.getByRole('button',{name:'Create User',exact:true}).click();
  await dialog.locator('.save-status.is-success').waitFor();
  ok(await dialog.locator('[name=temporary_password]').inputValue()==='','UI password cleared after success');
  ok(!(await page.evaluate(()=>JSON.stringify({local:{...localStorage},session:{...sessionStorage}}))).includes('TemporaryPass123'),'password absent from browser storage');
  await dialog.getByRole('button',{name:'OK',exact:true}).click();
  await page.waitForLoadState();
  ok(await page.getByText(fullName,{exact:true}).count()===1,'user name displayed as escaped text');
  ok(await page.locator('td script').count()===0,'user input creates no script element');
  // Rename via Manage Branches and see refreshed filters/listing.
  await page.getByRole('button',{name:'Manage Branches',exact:true}).click();
  const first=page.locator('#manageBranchesDialog .admin-profile-branch').first();
  await first.locator('summary').click();
  const newBranch='UI Branch & "Name" '+Date.now();
  await first.getByLabel('Branch name',{exact:true}).fill(newBranch);
  await first.getByRole('button',{name:'Save branch name',exact:true}).click();
  await first.locator('.save-status.is-success').waitFor();
  await first.getByRole('button',{name:'OK',exact:true}).click();await page.waitForLoadState();
  ok(await page.locator('select[name=branch] option').filter({hasText:newBranch}).count()===1,'UI rename refreshes branch selector');
  // Profile menu entry and actual SafeSubmit name save.
  await login('cashier'); await page.goto(base+'/dashboard');
  await page.locator('.user-dropdown summary').click();
  await page.getByRole('link',{name:'My Account',exact:true}).click();
  await page.getByRole('heading',{name:'Edit Name',exact:true}).waitFor();
  ok(page.url().endsWith('/account'),'My Account accessible from profile menu');
  for(const width of [360,768,1280]) {
    await page.setViewportSize({width,height:900});
    const m=await metrics('.page-content');
    ok(!m.overflow&&m.targets.length===0&&m.text.length===0,'profile layout sizing '+width+' '+JSON.stringify(m));
    await page.screenshot({path:`output/playwright/admin-profile-account-${width}.png`,fullPage:true});
  }
  await page.getByLabel('Display name',{exact:false}).fill('Immediate & "Profile"');
  await page.getByRole('button',{name:'Save name',exact:true}).click();
  await page.locator('.save-status.is-success').waitFor();
  ok(await page.locator('.user-copy strong').innerText()==='Immediate & "Profile"','profile name visible immediately without reload');
  ok((await(await ctx.request.get(base+'/__test/state')).json()).session.name==='Immediate & "Profile"','UI profile session refreshed');
  await page.getByRole('button',{name:'OK',exact:true}).click(); await page.waitForLoadState();
  ok(await page.locator('[name=name]').inputValue()==='Immediate & "Profile"','profile saved after reload');
  return {passed:evidence.length,evidence};
}
