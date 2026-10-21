from pathlib import Path
import hashlib, json, zipfile, datetime, difflib

ROOT = Path(__file__).resolve().parents[2]
WORK = Path(__file__).resolve().parent
STAMP = datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
NAME = 'malbcoff-admin-profile-update-' + STAMP
DESKTOP = Path.home() / 'Desktop'
FILES = [
    'actions/account_update.php', 'actions/system_admin_branch_rename.php',
    'actions/system_admin_user_create.php', 'actions/user_management.php',
    'assets/css/admin-profile.css', 'assets/js/admin-profile.js',
    'core/AccountChanges.php', 'core/UserManagement.php',
    'pages/account.php', 'pages/system-admin-users.php',
    'partials/admin-profile-dialogs.php', 'partials/footer.php', 'partials/header.php',
]
DEPS = ['core/Idempotency.php','core/helpers.php','core/Auth.php','core/Security.php',
        'assets/js/safe-submit.js','assets/js/app.js','index.php','.htaccess']
def sha(path): return hashlib.sha256(path.read_bytes()).hexdigest()
def readjson(path):
    raw = path.read_bytes()
    return json.loads(raw.decode('utf-16' if raw.startswith((b'\xff\xfe',b'\xfe\xff')) else 'utf-8-sig'))

pre = readjson(WORK/'pre-task-hashes.json')
baseline = readjson(ROOT/'deploy/baseline-manifest.json')
pending = readjson(ROOT/'deploy/last-package.json')
for file in ['deploy/baseline-manifest.json','deploy/last-package.json','deploy/migrations.sql','deploy/package-notes.txt']:
    assert sha(ROOT/file) == pre[file], f'Protected deployment record changed: {file}'
for file, digest in pending['files'].items():
    assert sha(ROOT/file) == digest, f'Earlier pending runtime file changed: {file}'
assert not set(FILES).intersection(pending['files']), 'Earlier pending shared-file dependency'
changed = [f for f in FILES if f in pre]
new = [f for f in FILES if f not in pre]
assert len(changed) == 6 and len(new) == 7
for file in changed:
    assert pre[file] == baseline['files'][file], f'Pre-task version diverged from live baseline: {file}'
    assert sha(ROOT/file) != pre[file], f'Unchanged file selected: {file}'
for file in new: assert file not in baseline['files'], f'New file already in baseline: {file}'
for file in DEPS: assert sha(ROOT/file) == baseline['files'][file], f'Changed prerequisite: {file}'
counts = {name: readjson(WORK/(name+'-result.json'))['passed'] for name in ['checks','extra','ui','history']}
assert sum(counts.values()) == 349

staged = WORK/'delivery'
staged.mkdir(exist_ok=True)
zip_path = staged/(NAME+'.zip')
guide_path = staged/(NAME+'.txt')
assert not zip_path.exists() and not guide_path.exists()
hashes = {f: sha(ROOT/f) for f in FILES}
with zipfile.ZipFile(zip_path, 'w', zipfile.ZIP_DEFLATED) as out:
    for file in FILES: out.write(ROOT/file, file)
with zipfile.ZipFile(zip_path) as archive:
    assert archive.testzip() is None
    assert archive.namelist() == FILES and len(set(archive.namelist())) == 13
    for info in archive.infolist():
        assert not info.is_dir() and not info.filename.startswith(('/', '\\'))
        assert '\\' not in info.filename and '..' not in Path(info.filename).parts
        assert hashlib.sha256(archive.read(info.filename)).hexdigest() == hashes[info.filename]

upload_order = ['core/UserManagement.php','core/AccountChanges.php','assets/css/admin-profile.css',
    'assets/js/admin-profile.js','partials/admin-profile-dialogs.php',
    'actions/system_admin_user_create.php','actions/system_admin_branch_rename.php',
    'actions/user_management.php','actions/account_update.php',
    'partials/header.php','partials/footer.php','pages/account.php','pages/system-admin-users.php']
destinations = '\n'.join(f'{i}. ZIP {f} -> APPLICATION_ROOT/{f}' for i, f in enumerate(upload_order,1))
digest_lines = '\n'.join(f'{hashes[f]}  {f}' for f in FILES)
pre_lines = '\n'.join(f'{pre[f]}  {f}' for f in changed)
guide = f'''MALBCOFF ADMIN AND PROFILE UPDATE
Status: done locally, not yet live
Prepared: {datetime.datetime.now().isoformat(timespec='seconds')} Asia/Manila
ZIP: {DESKTOP/(NAME+'.zip')}
ZIP SHA256: {sha(zip_path)}
Exactly 13 runtime files (7 new, 6 changed). No SQL migration.

WHAT THIS CHANGES
Direct System Admin > User Accounts: Add User and Manage Branches > Rename.
Roles: Owner, Branch Manager, Inventory Staff, Cashier. No System Admin creation.
Owner gets All Branches (NULL branch). Branch roles need an active branch.
Existing Owner validators are reused unchanged (name normalized and capped at
120 characters; email validated up to 160; password 12-128 bytes with a letter
and number). Password is hashed; first login forces a change.
Branch names: required, spaces normalized, maximum from schema (currently 100
characters); duplicate names use database collation. Only branches.name changes.
Inactive branches can also be renamed; their status and code stay unchanged.
My Account was already linked in the profile menu; verified for branch users.
Edit Name now has SafeSubmit, transactional retry protection, audit, explicit
foreign-account rejection, name-only updates and immediate session/topbar refresh.
Own display name is 1-60 characters; HTML/control characters are rejected.
Owner user management and Enter Account are preserved.

PREREQUISITES AND DEPENDENCIES
1. Start from confirmed-live baseline v3 (confirmed 2026-10-21), with existing
   security/impersonation schema, must_change_password and P3_001 name snapshot
   columns/triggers plus P3_002 idempotency_keys. Both are already in the confirmed
   baseline. Verify those existing prerequisites on staging; do not import old
   migrations from unrelated packages. Without safe-saving schema, saves refuse.
2. Existing PHP 8.2-compatible setup with PDO MySQL, mbstring and password_hash.
3. Upload ALL 13 files together. core/UserManagement.php must be present before
   replacing actions/user_management.php; core/AccountChanges.php before new
   endpoints and changed account pages. Header/footer load the new scoped assets.
4. No new routes, .htaccess edits, configuration, app-version change or migration
   is required. Normal filemtime asset URLs are used. No passwords go to browser
   storage, offline queues, saved retry fingerprints/results, logs or responses.
   Password input is online-only; Retry keeps it in memory only until completion.
5. No dependency on pending FIX 8C-2 or P3_007. Its 12 files were not changed or
   included. All six modified existing files matched v3 before this task; the
   eight unchanged auth/route/retry dependencies also match v3. The shared pending
   assets/css/app.css is deliberately excluded; this task has its own stylesheet.
6. Earlier pending record is preserved: deploy/last-package.json still refers to
   {pending['zip']}.
   That old ZIP was not found at the recorded Desktop path during this task.
   Its manifest and all 12 local source hashes remain intact. This new ZIP does
   not replace it. Keep the inventory deployment separate.

BACK UP FIRST
APPLICATION_ROOT means the live Malbcoff folder containing index.php and
bootstrap.php. Confirm that folder in your hosting file manager before copying.
No absolute hosting folder was assumed or accessed during this task.
1. Take a full database backup using your normal hosting backup method.
2. Download private backups of these SIX existing runtime files, preserving paths:
{chr(10).join('   '+f for f in changed)}
3. Record that the other SEVEN files are new; if any already exist on hosting,
   back them up too and reconcile that version before replacing it.
4. Keep backups outside the public application folder. Pause account/profile
   changes briefly while uploading the complete set; avoid mixed versions.
5. Extract this ZIP into a NEW local folder. Open it: actions/, assets/, core/,
   pages/ and partials/ must be directly inside (no extra wrapper directory).

UPLOAD ONE FILE AT A TIME, PRESERVING DESTINATIONS
Use Copy/Paste or your host's upload-and-replace command. Open the matching
destination directory first; never drop a folder's contents into the app root.
Do not upload this TXT, vault, deploy state, test files or local configuration.
Migration order: NONE for this package. No SQL to import.
{destinations}

VERIFICATION AFTER UPLOAD
1. Reload User Accounts after a direct System Admin sign-in. Add User and Manage
   Branches must appear, and Enter Account must still work for Owner/branch users.
2. In an isolated staging copy with synthetic accounts/branches, create each
   supported role; check Owner = All Branches and branch roles = active branch.
   Try empty/invalid fields and a duplicate email (including case variants).
3. Sign in with a new temporary password: business pages/actions must stay locked
   until the password is changed. Never display the password/hash or log payloads.
4. On staging, rename an active and inactive branch. Reject blank, duplicate and
   over-100-character names. Compare branch IDs/codes/assignments, inventory,
   sales, transfers and snapshots before/after; only branch name changes.
   Current filters and account listings must show the new branch name.
5. As branch staff, open profile menu > My Account > Edit Name. Verify the topbar
   updates immediately and the value survives reload. Reject blank/61-character
   names, another user's id/user_id/target_user_id and protected-field changes.
6. On staging verify Owner/branch/anonymous/impersonated sessions cannot invoke
   the new admin endpoints; impersonation cannot change profiles. Reject bad
   CSRF/GET, retry the same key and simulate a lost response: no duplicate write.
7. Verify Owner Create/Edit/Activate/Deactivate/Reset Password and Enter Account /
   Return to System Admin. Check 360px, 768px and desktop; tables can scroll only
   within their labelled wrapper. Confirm no page-wide horizontal scroll.
8. Historical cashier/stock/transfer snapshots keep old names; new records take
   the new display name. Use synthetic staging records for mutation tests, not
   real accounts or branch names just to test deployment. Live review can verify
   controls/listings with no test mutation.

LOCAL VERIFICATION EVIDENCE
349 isolated assertions passed: main HTTP/browser {counts['checks']}, additional
retry/Owner/native-form {counts['extra']}, responsive/UI {counts['ui']}, original
history/new snapshot/validator preservation {counts['history']}.
Actual SafeSubmit creation, branch rename and self rename succeeded. Lost
response after commit retried the same key and created only one user. Stale CSRF
recovered once. Password absent from response/audit/retry data and browser storage
including IndexedDB in the simulated failure. Owner validators moved unchanged.
11 runtime PHP syntax checks, JavaScript syntax and task-scoped whitespace checks
passed. ZIP has exact paths, 13/13 matching source hashes and no excluded content.
DB: malbcoff_admin_profile_20261021 (local schema clone, synthetic records only).
No real account or branch name was changed for testing. No live hosting accessed.
Not performed: live hosting/staging UAT, real devices/Safari/Firefox, parallel
multi-worker load/stress or real network outage testing (lost response simulated).

ROLLBACK
1. Pause account/profile changes again. Restore the SIX backed-up runtime files
   to exactly their original paths. Then remove the SEVEN new files only if this
   package introduced them and no later patch uses them. No schema rollback.
2. Restoring PHP/JS/CSS does not undo successfully saved users/names. Keep created
   IDs and transaction history. If an intended business change must be undone,
   restore only the affected display/branch name or deactivate a new account
   through an authorized workflow. Do not delete history. A full DB restore is
   appropriate only if you can safely discard ALL later writes; otherwise use a
   reviewed targeted correction from the backup.
3. Reload and verify Owner management, Enter Account and the old My Account flow.
4. Keep FIX 8C-2 deployment records and confirmed-live baseline unchanged.

DEPLOYMENT TRACKING
Scoped record: deploy/admin-profile-update-{STAMP}.json (local only).
This delivery is NOT marked live. Do not run confirm-deployed.ps1 against the
unrelated last-package.json. Reply "deployed" and identify this ZIP after upload;
only then reconcile this scoped manifest into the baseline. FIX 8C-2 stays pending
unless explicitly confirmed separately.

UPLOAD FILE SHA256
{digest_lines}

PRE-TASK / CONFIRMED-LIVE HASHES FOR THE SIX REPLACED FILES
{pre_lines}
'''
guide_path.write_text(guide, encoding='utf-8')
diff = []
for file in FILES:
    old = (WORK/'pre-task'/file).read_text(encoding='utf-8-sig').splitlines(True) if file in pre else []
    current = (ROOT/file).read_text(encoding='utf-8-sig').splitlines(True)
    diff.extend(difflib.unified_diff(old, current, fromfile='pre-task/'+file, tofile='task/'+file))
(WORK/'task-only.diff').write_text(''.join(diff), encoding='utf-8')
record = dict(scope='System Admin Add User, Rename Branch, self-profile name', status='done locally, not yet live',
    built_at=datetime.datetime.now().isoformat(timespec='seconds'), baseline_version=baseline['version'],
    zip=str(DESKTOP/zip_path.name), guide=str(DESKTOP/guide_path.name), zip_sha256=sha(zip_path),
    guide_sha256=sha(guide_path), files=hashes, new_files=new, changed_files=changed,
    pre_task_files={f:pre[f] for f in changed}, unchanged_dependencies={f:sha(ROOT/f) for f in DEPS},
    pending_package_dependencies=[], migrations=[], prerequisites=['Existing security/impersonation schema','P3_001 snapshot triggers','P3_002 idempotency_keys'],
    verification_counts=counts, verification_total=sum(counts.values()),
    preserved_deploy_state={f:pre[f] for f in ['deploy/baseline-manifest.json','deploy/last-package.json','deploy/migrations.sql','deploy/package-notes.txt']},
    earlier_pending_zip_exists=Path(pending['zip']).is_file(), staged_zip=str(zip_path), staged_guide=str(guide_path),
    scoped_record='deploy/admin-profile-update-'+STAMP+'.json')
(WORK/'package-manifest.json').write_text(json.dumps(record,indent=2),encoding='utf-8')
print(json.dumps({'zip':str(zip_path),'guide':str(guide_path),'runtime_files':len(FILES),'sha256':sha(zip_path),'checks':sum(counts.values())}))
