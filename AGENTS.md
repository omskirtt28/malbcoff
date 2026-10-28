# Malbcoff: rules for Codex (read first, keep short)

Language: Taglish, short. Plan before patching. Do not rush.

## Token saving
- Start from the vault: `malbcoff-vault/00 Start Here.md` (Current work / deployment status) and `malbcoff-vault/Plan/Deploy Rule.md`. Read malbcoff-vault/00 Start Here.md first.
- Do not read the whole repo; open only the files you need and use `rg` to search. Do not read .git, vendor, uploads, storage, backups or any generated folder.
- Max 3 test scenarios, run once, unless asked otherwise. Final answer: what changed and the file list only.

## How to edit
- Files are CRLF: no whole-file rewrite; targeted edits only. Afterwards run `git diff --stat` to confirm a small diff and no line-ending churn.
- Fix sweep (mandatory): when John points at one issue, fix it, then grep the same pattern across the project (UI: spacing/size/states/overflow/handlers/labels; backend: validation, CSRF, permission/role check, escaping, prepared queries, UI vs POST/AJAX mismatch). Fix small, clearly same-class items; only LIST (do not touch) schema, auth/permission rules, payments, deletes and anything large.
- Project rules: update the matching vault note and Changelog.md; build the update package ONLY on a trigger phrase (deploy\build-update-package.ps1); never touch deploy/baseline-manifest.json or config with secrets; additive SQL only.
- Every SQL file: clear note to select the database first, additive only, with a verification step in the README.
- No browser/UI testing. After an edit run `php -l` (PHP) / `node --check` (JS) only.
- Do not deploy, push or commit. Do not change permission/data/schema/payments unless the plan says so; stop and report instead.
- Final report: Fixed (file:line) / Found and also fixed / Found but left alone (why) / Not verified.
