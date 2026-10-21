async (page) => {
  page.setDefaultTimeout(10000);
  const base='http://127.0.0.1:8881', ctx=page.context(), evidence=[], stamp=Date.now();
  let csrf='';
  const ok=(v,m)=>{if(!v)throw new Error(m);evidence.push('PASS '+m);};
  const state=async()=>await(await ctx.request.get(base+'/__test/state')).json();
  const login=async(role)=>{await page.goto(base+'/__test/login?role='+role);csrf=JSON.parse(await page.locator('body').innerText()).csrf;};
  const post=async(endpoint,data,key=crypto.randomUUID())=>{
    const response=await ctx.request.post(base+'/actions/'+endpoint+'.php',{headers:{Accept:'application/json','X-Idempotency-Key':key},form:{_csrf:csrf,...data}});
    const text=await response.text();let body;try{body=JSON.parse(text);}catch{throw new Error('Invalid JSON '+endpoint+' '+response.status()+' '+text.slice(0,150));}
    ok(!text.includes('TemporaryPass123')&&!text.includes('password_hash'),'response contains no credential: '+endpoint);
    return {status:response.status(),...body};
  };
  await login('system_admin');
  const before=await state(), b1=before.branches.find(b=>b.code==='TEST-C').id, b2=before.branches.find(b=>b.code==='TEST-N').id, inactive=before.branches.find(b=>b.code==='TEST-I').id;
  const create={name:'Created User',email:`created-${stamp}@test.invalid`,role:'cashier',branch_id:b1,temporary_password:'TemporaryPass123'};
  for(const role of ['owner','branch_manager','inventory','cashier']){
    await login(role);
    ok((await post('system_admin_user_create',create)).status===403,role+' cannot use admin Add User');
    ok((await post('system_admin_branch_rename',{branch_id:b1,name:'Forbidden'})).status===403,role+' cannot rename branches');
  }
  await login('system_admin');
  for(const endpoint of ['system_admin_user_create','system_admin_branch_rename']) {
    ok((await post(endpoint,{...create,_csrf:'bad'})).status===419,endpoint+' CSRF rejected');
    ok((await ctx.request.get(base+'/actions/'+endpoint+'.php',{headers:{Accept:'application/json'}})).status()===405,endpoint+' GET rejected');
  }
  for(const [patch,label] of [[{name:'  '},'empty name'],[{email:'bad'},'invalid email'],[{email:'x'.repeat(155)+'@test.invalid'},'long email'],[{role:'system_admin'},'unsupported admin role'],[{role:'bogus'},'unknown role'],[{branch_id:inactive},'inactive branch'],[{branch_id:9999999},'missing branch'],[{branch_id:''},'required branch'],[{temporary_password:'short1'},'short password'],[{temporary_password:'abcdefghijkl'},'password without number'],[{temporary_password:'123456789012'},'password without letter'],[{temporary_password:'a1'.repeat(65)},'long password']]) {
    ok((await post('system_admin_user_create',{...create,...patch})).status===422,'create rejects '+label);
  }
  const key=crypto.randomUUID();
  ok((await post('system_admin_user_create',create,key)).ok,'cashier created');
  ok((await post('system_admin_user_create',create,key)).replayed,'create duplicate key replays');
  ok((await post('system_admin_user_create',{...create,email:create.email.toUpperCase()})).status===422,'case-insensitive duplicate email rejected');
  ok((await post('system_admin_user_create',{...create,email:'different-'+create.email},key)).status===422,'key cannot create a second account');
  for(const role of ['owner','branch_manager','inventory']) {
    ok((await post('system_admin_user_create',{...create,email:`created-${role}-${stamp}@test.invalid`,role,branch_id:role==='owner'?inactive:b1})).ok,'create supported role '+role);
  }
  let after=await state();
  const created=after.users.find(u=>u.email===create.email);
  ok(after.users.filter(u=>u.email===create.email).length===1,'only one created user');
  ok(created.must_change_password===1&&created.password_changed_at===null&&after.password_ok[created.id]===true,'password hashed and first-login change required');
  ok(after.users.find(u=>u.email===`created-owner-${stamp}@test.invalid`).branch_id===null,'Owner uses All Branches');
  const rename={branch_id:b1,name:`Central & North ${stamp}`}, branchKey=crypto.randomUUID();
  for(const [data,label] of [[{branch_id:b1,name:'  '},'empty'],[{branch_id:b1,name:'x'.repeat(101)},'101 characters'],[{branch_id:b1,name:before.branches.find(b=>b.id===b2).name.toLowerCase()},'duplicate case variant'],[{branch_id:9999999,name:'Missing'},'missing ID'],[{branch_id:b1,name:'<script>'},'HTML']]) ok((await post('system_admin_branch_rename',data)).status===422,'branch rename rejects '+label);
  ok((await post('system_admin_branch_rename',rename,branchKey)).ok,'branch renamed');
  ok((await post('system_admin_branch_rename',rename,branchKey)).replayed,'branch rename key replays');
  ok((await post('system_admin_branch_rename',{branch_id:b2,name:rename.name})).status===422,'second branch cannot take renamed name');
  after=await state();
  ok(JSON.stringify(after.branches.map(b=>({...b,name:''})))===JSON.stringify(before.branches.map(b=>({...b,name:''}))),'branch IDs codes status and timestamps unchanged');
  await page.goto(base+'/system-admin-users');
  ok(await page.locator('select[name="branch"] option').filter({hasText:rename.name}).count()===1,'branch filter uses current name');
  ok(await page.locator('td[data-label="Branch"]').filter({hasText:rename.name}).count()>0,'account listing uses current name');
  // Owner management remains the existing action and modal, with shared validators.
  await login('owner');await page.goto(base+'/users');
  ok(await page.locator('[data-user-create]').count()===1,'Owner Add User retained');
  await page.locator('[data-user-create]').click();
  ok(await page.locator('#userAccountModal').isVisible(),'Owner modal opens');
  const ownerResponse=await ctx.request.post(base+'/actions/user_management.php',{form:{_csrf:csrf,action:'create',...create,email:`owner-created-${stamp}@test.invalid`}});
  ok(ownerResponse.status()===200&&(await state()).users.some(u=>u.email===`owner-created-${stamp}@test.invalid`),'Owner creation still works');
  // Enter/return as Owner and branch user; admin actions remain blocked under both effective roles.
  for(const role of ['owner','cashier']) {
    await login('system_admin');
    const target=before.users.find(u=>u.role===role).id;
    await ctx.request.post(base+'/actions/impersonation.php',{form:{_csrf:csrf,action:'start',target_user_id:target}});
    await page.goto(base+'/account');
    const impCsrf=await page.locator('input[name="_csrf"]').first().inputValue();csrf=impCsrf;
    ok(await page.locator('.impersonation-banner').count()===1,'Enter Account works for '+role);
    ok((await post('system_admin_user_create',create)).status===403,'impersonated '+role+' denied admin create');
    ok((await post('system_admin_branch_rename',rename)).status===403,'impersonated '+role+' denied branch rename');
    ok((await post('account_update',{name:'Wrong'})).status===403,'impersonated '+role+' denied profile rename');
    await ctx.request.post(base+'/actions/impersonation.php',{form:{_csrf:csrf,action:'end'}});
    await page.goto(base+'/system-admin-users');
    ok(await page.getByRole('heading',{name:'User Accounts',exact:true}).count()===1,'Return to System Admin works for '+role);
  }
  await login('system_admin');ok((await post('account_update',{name:'Admin'})).status===403,'direct admin cannot use business self rename');
  for(const role of ['owner','branch_manager','inventory','cashier']) {
    await login(role);
    const own=before.users.find(u=>u.role===role), other=before.users.find(u=>u.role==='system_admin');
    for(const field of ['id','user_id','target_user_id']) ok((await post('account_update',{name:'Other', [field]:other.id})).status===422,role+' rejects foreign '+field);
    for(const field of ['email','role','branch_id','is_active','status','password','temporary_password','password_hash']) ok((await post('account_update',{name:'Wrong',[field]:'tampered'})).status===422,role+' rejects '+field+' update');
    for(const name of ['', ' ', 'x'.repeat(61), '<b>Name</b>', 'x\u0000y']) ok((await post('account_update',{name})).status===422,role+' rejects invalid display name');
    ok((await post('account_update',{name:'x'.repeat(60)})).ok,role+' accepts 60 characters');
    const profileKey=crypto.randomUUID(), profile={name:`Renamed & "${role}"`};
    ok((await post('account_update',profile,profileKey)).ok,role+' self rename succeeds');
    ok((await post('account_update',profile,profileKey)).replayed,role+' self rename replays');
    let s=await state();
    ok(s.session.name===profile.name,role+' session refreshed immediately');
    const changed=s.users.find(u=>u.id===own.id);
    ok(['email','role','branch_id','is_active','must_change_password','password_changed_at'].every(k=>changed[k]===own[k]),role+' identity permissions and status unchanged');
    ok((await post('account_update',{name:'CSRF',_csrf:'bad'})).status===419,role+' profile CSRF rejected');
  }
  after=await state();
  for(const table of ['sales','sale_items','stock_movements','inventory_transfers','inventory_units']) ok(JSON.stringify(after[table])===JSON.stringify(before[table]),table+' history unchanged');
  const stored=JSON.stringify({audit:after.security_audit_logs,keys:after.idempotency_keys});
  ok(!stored.includes('TemporaryPass123')&&!stored.includes('password_hash')&&!stored.includes('$2y$'),'no credentials in audit/retry records');
  await ctx.request.get(base+'/__test/logout');
  for(const endpoint of ['system_admin_user_create','system_admin_branch_rename','account_update']) ok((await post(endpoint,create)).status===401,'anonymous denied '+endpoint);
  // Actual password sign-in, central gate, password replacement, then business page access.
  await page.goto(base+'/login');
  let token=await page.locator('input[name="_csrf"]').inputValue();
  await ctx.request.post(base+'/login',{form:{_csrf:token,email:create.email,password:'TemporaryPass123'}});
  await page.goto(base+'/dashboard');
  ok(page.url().endsWith('/change-password'),'first login redirects to password change');
  ok((await post('account_update',{name:'Blocked'})).status===403,'password change gate blocks actions');
  token=await page.locator('input[name="_csrf"]').inputValue();
  await ctx.request.post(base+'/change-password',{form:{_csrf:token,new_password:'ReplacementPass456',confirm_password:'ReplacementPass456'}});
  await page.goto(base+'/dashboard');
  ok(!page.url().endsWith('/change-password'),'new password unlocks business pages');
  return {passed:evidence.length,evidence};
}
