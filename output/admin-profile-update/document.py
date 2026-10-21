from pathlib import Path
import json, hashlib, re

ROOT=Path(__file__).resolve().parents[2]
WORK=Path(__file__).resolve().parent
record=json.loads((WORK/'package-manifest.json').read_text(encoding='utf-8'))
def sha(path):return hashlib.sha256(Path(path).read_bytes()).hexdigest()
assert sha(record['zip'])==record['zip_sha256']
assert sha(record['guide'])==record['guide_sha256']
for file,digest in record['preserved_deploy_state'].items():assert sha(ROOT/file)==digest
scoped=ROOT/record['scoped_record']
assert not scoped.exists(), 'Never overwrite a previous scoped delivery record'
scoped.write_text(json.dumps(record,indent=2),encoding='utf-8')

def edit(file,body,at_top=False,replacements=()):
    path=ROOT/'malbcoff-vault'/file
    raw=path.read_bytes();bom=raw.startswith(b'\xef\xbb\xbf')
    text=raw.decode('utf-8-sig');nl='\r\n' if '\r\n' in text else '\n'
    for old,new in replacements:
        assert old in text, f'Missing note text: {file}: {old}'
        text=text.replace(old,new,1)
    body=body.strip().replace('\n',nl)
    if at_top:
        match=re.search(r'(?m)^# [^\r\n]+\r?\n',text)
        assert match
        text=text[:match.end()]+nl+body+nl+text[match.end():]
    else:
        text=text.rstrip()+nl+nl+body+nl
    path.write_bytes((b'\xef\xbb\xbf' if bom else b'')+text.encode('utf-8'))

status='**Status: done locally, not yet live.** No live hosting access or deployment confirmation.'
links=f"ZIP: `{record['zip']}`. Separate upload/rollback guide: `{record['guide']}`."
evidence='349 isolated assertions passed (287 main HTTP/browser + 19 retry/Owner/native-form + 34 UI/responsive + 9 original-history/new-snapshot/validator checks); 11 runtime PHP syntax checks, JavaScript syntax and task-scoped whitespace checks passed.'
prereq='No migration. Requires the existing security/impersonation schema, must_change_password, P3_001 snapshot columns/triggers and P3_002 idempotency_keys, already in confirmed-live baseline v3. No dependency on FIX 8C-2/P3_007.'
files='\n'.join('- `'+f+'`'+(' (new)' if f in record['new_files'] else ' (changed)') for f in record['files'])

edit('Features/Users and Accounts.md',f'''## 2026-10-21 - System Admin and profile update
{status}

- Direct System Admin > User Accounts now has **Add User** and **Manage Branches**. Both endpoints require direct System Admin authentication outside impersonation, POST, CSRF, current active-user permission revalidation, a valid retry key, row locks, a transaction and the existing audit helper.
- Add User: Full Name, Email, Role, Branch, Temporary Password. Reuses the unchanged Owner validators in `core/UserManagement.php`: owner/branch_manager/inventory/cashier only; normalized name capped at 120, email validation up to 160, 12-128-byte password with letter and number. Owner always stores NULL branch (All Branches); other roles require an active branch. Duplicate email rejected by check and existing unique index. Hash + must_change_password=1; no password/hash is returned, logged or stored in browser/outbox/idempotency intent/results.
- Manage Branches lists active and inactive branches with Rename. Required normalized name, current schema maximum (100 characters), no HTML/control characters, duplicate check using database collation. Ordered branch locks serialize renames without a schema change. Only `branches.name` changes; codes, IDs, status, assignments, stock, money, transfers and snapshots stay intact. Current selectors/account listings read the new name from branches.
- My Account was already present in the profile menu; branch access was verified. Edit Name now uses SafeSubmit + transactional idempotency/audit. Session ID selects the row; foreign id/user_id/target_user_id and protected-field attempts are rejected. Own name remains 1-60 characters, normalized and HTML/control-free. Session and visible topbar name refresh immediately. History is never updated; old snapshot values remain, new records use the new name.
- Owner Create/Edit/Activate/Deactivate/Reset Password and Enter Account/Return are preserved. Shared validator bodies match the pre-task file byte for byte. No new System Admin creation and no profile edit during impersonation.
- New UI uses native dialogs, focus return/Escape, English copy, SafeSubmit states and a separate scoped stylesheet. Phone account cards and keyboard-accessible wider-table scroll; 360/768/1280 checked, no horizontal page scroll, content controls >=44px and text >=14px.

{prereq}

{evidence} Test DB: `malbcoff_admin_profile_20261021`, schema cloned without real rows; synthetic fixtures only. Simulated lost response after commit retried the same key and created one user; stale CSRF recovered. Historical rows compared unchanged and new snapshot triggers verified in a rolled-back test transaction. Real accounts/branch names were not modified for tests.

{links}

Exact 13 runtime files, SHA256s, prerequisites and upload order: [[Deploy Package]]. Pre-task copies/hashes and task-only diff: `output/admin-profile-update/`. Verification scripts: `tests/admin-profile/`, evidence JSON and screenshots: `output/admin-profile-update/` and `output/playwright/admin-profile-*`. All are excluded from the ZIP. Not performed: hosting/staging UAT, real-device/Safari/Firefox checks, parallel multi-worker stress and real outage testing.''')

edit('Roles and Access.md',f'''## 2026-10-21 - Current account mutation permissions
{status}

| Action | Direct System Admin | Owner | Branch Manager / Inventory / Cashier | Impersonation |
|---|---|---|---|---|
| New System Admin Add User endpoint | Yes | No | No | No |
| Rename Branch | Yes | No | No | No |
| Own display name via My Account | No (business account page) | Yes | Yes | No |
| Existing Owner user management | Via existing Owner Enter Account view | Yes, unchanged | Existing manager view only | Existing Owner permissions preserved |
| Enter Account / Return | Yes, existing workflow | No start | No start | Return only |

The new admin endpoints check effective direct role plus `!Auth::isImpersonating()`, then re-read active-user permission from the database and revalidate under lock. Self rename derives ID from Auth session, rejects another account ID, and cannot alter email, role, branch or account status. POST + CSRF + idempotency + transaction are enforced server-side. No client-only permission gates. See [[Users and Accounts]] and [[Deploy Package]] for 349-check evidence and package details.''')

edit('File Index.md',f'''## 2026-10-21 - Admin/profile runtime patch (13 files)
{status}

{files}

`core/UserManagement.php` holds the unchanged Owner validation functions shared with admin creation. `core/AccountChanges.php` owns session/role/CSRF checks, server-generated form keys, idempotent transactional save/replay and safe replies. Admin endpoints create business users and rename only branch names; `account_update.php` updates only the session user's display name. Existing topbar My Account link and Enter Account endpoint were verified without edits. Header/footer include the new assets only on User Accounts/My Account.

{prereq}

Tests (excluded): `tests/admin-profile/setup.php`, `router.php`, `checks.js`, `extra.js`, `ui.js`, `history.php`; JSON evidence and pre-task versions/hashes/task diff/scoped package manifest in `output/admin-profile-update/`; screenshots `output/playwright/admin-profile-*`. {evidence}

{links}

Local immutable deployment record: `{record['scoped_record']}`. Existing `deploy/last-package.json`, baseline v3, pending FIX 8C-2 files and migrations are preserved.''',replacements=[('Self-service password change (only self-edit that exists today)','Self-service password change')])

summary=f'''## 2026-10-21 - Admin/profile update, separate pending delivery
{status}

System Admin Add User + Manage Branches/Rename and completed self-name protection are implemented on current XAMPP source. {evidence} {prereq}

{links}

Exactly 13 runtime files; ZIP paths/CRC/SHA256 checked. Six changed existing files matched confirmed-live v3 before edits; seven are new. Eight auth/route/retry dependencies match v3. No overlap with the 12 pending FIX 8C-2 runtime files; that record and baseline remain unchanged. Prior FIX 8C-2 ZIP is absent at its recorded Desktop path; its record and all 12 local matching hashes are preserved. Reply **deployed** identifying this ZIP before recording deployment. See [[Users and Accounts]] / [[Deploy Package]].'''

edit('00 Start Here.md',summary,at_top=True,replacements=[('Only FIX 8C-2 is **done, not yet live**.','FIX 8C-2 remains **done, not yet live**; the separate admin/profile update above is also pending.')])
edit('Changelog.md',summary,at_top=True,replacements=[('Only FIX 8C-2 is done, not yet live.','FIX 8C-2 and the admin/profile update above are done locally, not yet live.')])

edit('Plan/Deploy Package.md',f'''## Admin/profile update - 2026-10-21 - latest-only Desktop delivery
{status}

{links}

ZIP SHA256: `{record['zip_sha256']}`. Exactly 13 runtime entries, forward-slash app-root paths, no wrapper folder; 13/13 ZIP bytes match current sources. No vault, tests, screenshots, backups, logs, uploads, secrets/config, .git, tools, old ZIPs, README or SQL inside. Guide is separate on Desktop.

### Runtime files
{files}

### Baseline and dependency audit
- Baseline v3 and existing pending records were captured before editing, with six replaced runtime versions and hashes. These six matched the confirmed-live manifest, so no earlier unconfirmed changes are silently bundled. Seven new files were absent from that manifest.
- `core/Idempotency.php`, `core/helpers.php`, `core/Auth.php`, `core/Security.php`, `assets/js/safe-submit.js`, `assets/js/app.js`, `index.php`, `.htaccess` match confirmed-live hashes and remain unchanged. New required helper/partial/asset dependencies are all included.
- No shared path with FIX 8C-2. `assets/css/app.css` remains its pending version, excluded here; the new `admin-profile.css` supplies task-only styling. No P3_007 requirement. Prior pending ZIP absent at its recorded Desktop path, but its last-package record and 12 local hashes are intact.
- `deploy/baseline-manifest.json`, `deploy/last-package.json`, `deploy/migrations.sql`, `deploy/package-notes.txt` are byte-for-byte unchanged. This delivery is recorded separately in `{record['scoped_record']}`. Do not run confirm-deployed.ps1 against the unrelated inventory last-package record.
- {prereq}

### Upload and rollback
Back up the database and six existing replaced files first, preserving paths; record whether the seven new paths already exist. Upload all 13 to the exact app-root-relative destinations in the Desktop TXT: new core helpers/assets/partial first, action endpoints next, layout/pages last. `core/UserManagement.php` must precede the refactored Owner action. No SQL, config, app-version or rewrite change. Use normal filemtime assets. Pause account/profile writes briefly during upload to avoid partial versions.

Run mutation smoke tests in an isolated staging copy with synthetic data. Review existing live controls/listings without renaming real branches/accounts just for testing. Guide covers direct admin roles, first-login password gate, duplicate/invalid/CSRF/foreign-ID checks, lost-response Retry, Owner management, Enter Account and history/responsive checks.

Rollback: restore the six backed-up files, then remove only the seven files introduced by this package if no newer patch depends on them. No schema rollback. Code rollback does not undo saved users/names; preserve IDs/history, use authorized targeted correction/deactivation when needed. Full database restore would discard later writes and must be reviewed. Do not alter the inventory pending package or baseline.

### Verification
{evidence}

Isolated DB `malbcoff_admin_profile_20261021`: empty current schema (22 tables, 5 existing triggers) plus synthetic records; no real rows copied. All three SafeSubmit flows succeeded. First-login password change unlocked business pages only after replacement. Duplicate emails/branch names, inactive/missing branch creation, invalid names/passwords, unauthorized/anonymous/impersonated sessions, CSRF, repeated keys, foreign user IDs and protected profile fields were checked. Owner Create/Edit/Activate/Deactivate/Reset plus Enter/Return passed. Simulated response loss committed once; Retry reused its key. Storage including IndexedDB contained no temporary password. Original transaction/stock/transfer rows stayed byte-equivalent; new snapshot triggers captured the new name in a rolled-back test.

Pre-task evidence: `output/admin-profile-update/pre-task/`, `pre-task-hashes.json`, `pre-task-git-status.txt`, `task-only.diff`. Results: `checks-result.json`, `extra-result.json`, `ui-result.json`, `history-result.json`. Twelve screenshots: `output/playwright/admin-profile-*` at 360/768/1280; visually reviewed representative phone/tablet/desktop views. Task support files excluded from deployment.

Not performed: live hosting/staging UAT, real devices or Safari/Firefox, parallel multi-worker load/stress or actual network outage (response loss simulated). Wait for explicit **deployed** confirmation naming this ZIP. FIX 8C-2 remains separately pending.''',at_top=True,replacements=[('## Current deployment status','## Previous deployment status (before the admin/profile task)')])

edit('Plan/Deploy Rule.md',f'''## Admin/profile scoped delivery - 2026-10-21
{status}

{links}

User explicitly requested latest-only runtime files and a separate TXT, so this scoped package does not run the cumulative builder or overwrite `deploy/last-package.json`. Baseline remains v3; pending FIX 8C-2 record/files/migration remain unchanged. Its old ZIP was not found at the recorded Desktop path; no deployment was inferred. {prereq}

Immutable scoped record: `{record['scoped_record']}`; pre-task versions/hashes, exact 13-file list, ZIP/source hashes, dependency audit and 349-check evidence are in [[Deploy Package]] and `output/admin-profile-update/`. On a future explicit deployed confirmation, identify which package was uploaded and reconcile only that manifest. Never advance this task or FIX 8C-2 merely because either package was created. Earlier status paragraphs below describe their prior checkpoints.''',at_top=True)

edit('Plan/Patch 5 - Edit Account.md',f'''## 2026-10-21 amendment
{status}

The earlier acceptance above records historical behavior. Current action now explicitly rejects another account's id/user_id/target_user_id (rather than ignoring it), rejects protected-field attempts, and uses CSRF + SafeSubmit + transactional idempotency/audit. The session and topbar refresh immediately. Existing profile-menu My Account access and 60-character validation remain. Old snapshot rows stay unchanged; current trigger capture was reverified. See [[Users and Accounts]] and [[Deploy Package]] for the 349-check evidence, 13 runtime files, separate Desktop ZIP/TXT and prerequisites.''')

for file,digest in record['preserved_deploy_state'].items():assert sha(ROOT/file)==digest
print(json.dumps({'vault_notes_updated':8,'scoped_record':record['scoped_record'],'status':record['status']}))
