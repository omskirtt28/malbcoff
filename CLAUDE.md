Basahin muna ang malbcoff-vault/00 Start Here.md bago mag-explore ng code; i-update ang kaukulang note pagkatapos ng bawat patch.

## Patch delivery rule (permanente) — buong detalye: malbcoff-vault/Plan/Deploy Rule.md
- **Trigger phrases** (English/Taglish): "akin na yung patch", "give me the patch", "ibigay mo yung patch", "latest patch", "deploy package", "patch na".
- **Kapag trigger:** huwag magtanong at huwag i-re-read ang buong project. Patakbuhin agad `powershell -NoProfile -ExecutionPolicy Bypass -File deploy\build-update-package.ps1`. Gumagawa ito ng package na may **LANG files na bago/binago** laban sa `deploy/baseline-manifest.json` (ang nasa LIVE; hindi kailanman kasama sa package; git-ignored). Output: `%USERPROFILE%\Desktop\malbcoff-deploy-update-<yyyy-mm-dd-hhmm>\` + kapangalang `.zip`, `deploy/migrations.sql` (bagong migrations lang, re-runnable) at plain-English `deploy/README.txt`. Manual na hakbang (config lines, `.htaccess`) → isulat muna sa `deploy/package-notes.txt`.
- Hindi kasama kailanman: vault, CLAUDE.md, .git, node_modules, backups, logs, uploads, caches, tests, ibang deploy folders, real config na may secrets.
- Tapusin sa listahan ng new/changed files, zip path, baseline version; sabihin: **reply "deployed" after uploading**.
- **Kapag "deployed" / "na-deploy ko na" / "live na":** patakbuhin `deploy\confirm-deployed.ps1` (ililipat ang baseline sa eksaktong estado ng huling na-deliver na package), tapos itala ang petsa sa Plan/Deploy Rule.md at Changelog.md. Walang confirmation = hindi gumagalaw ang baseline, kaya kasama pa rin ang unconfirmed na pagbabago sa susunod.

## Fix sweep (mandatory)
When John points at one issue (UI, UX or backend), do not fix only that. Fix it, then grep the same pattern across the project:
- UI: same kind of button/component/layout, inconsistent spacing/size/colour/radius, overflow and responsive breakage, missing states (hover, focus, disabled, loading, empty, error), buttons with no handler, inconsistent labels or wording.
- Backend: the same bug in other pages/handlers, missing validation, missing CSRF or permission/role check, unescaped output, non-prepared queries, missing error handling, UI vs POST/AJAX/API mismatches.

Fix small, clearly same-class items in the same patch. List, do not touch: schema changes, auth/permission rules, payments, deletes, anything large. No redesign, no unrelated features. Final report: Fixed (file:line) / Found and also fixed / Found but left alone (why) / Not verified. "Sweep" alone means apply this checklist to the whole project (plan first if large).

## Agent pipeline (automatic, mandatory)
Every prompt that asks to build, fix, change or patch something goes through this pipeline. John does not have to ask for it. The main session is the orchestrator (subagents cannot call other subagents).

1. **Plan**: call `planner` first. It returns the goal, the frontend/backend split, exact files, order, the sweep list, risks and up to 3 test scenarios.
   If the plan touches schema, roles/auth, payments, the stock ledger or deletes, stop and ask John before building. Otherwise continue automatically.
2. **Build**: call `backend-engineer` and `ux-designer` (both 10 years experience) with their part of the plan. Backend first when the UI depends on a new endpoint/contract; in parallel only when their file sets do not overlap (both edit the repo).
3. **Test + critique**: call `patch-tester`. It runs the checks, tries the scenarios, critiques against the plan, the project rules, security and the sweep, and returns PASS / FAIL / PASS WITH NOTES.
4. **Fix loop**: on FAIL or MISSED SWEEP, send it back to the right engineer, then test again. Maximum 2 rounds; after that report honestly what still fails.
5. **Finish**: update malbcoff-vault (the matching note and Changelog.md). Build the update package ONLY when John says a trigger phrase (see Patch delivery rule above); never automatically. The final report follows the Fix sweep format.

Skip the pipeline for pure questions, docs-only work, and one-line fixes. Existing project rules above take priority over this section.

## Session start and auto-update (mandatory)
- At the start of every session read `malbcoff-vault/00 Start Here.md` (Current work / deployment status) and `malbcoff-vault/Plan/Deploy Rule.md`, so John does not have to re-explain. Read malbcoff-vault/00 Start Here.md first. Use grep + partial reads; do not scan the repo.
- After every patch AND after any agreed topic/decision in chat, update the vault on your own (module note, changelog/session log, status), without waiting for a reminder.
- When John says a feature should be "the same as" an existing module, copy it AS IS and decide on your own; do not ask for details. Ask only when there is risk to permission, data or schema.
- Optional `simplify-gatekeeper` (review only): PROPOSALS para kay John; huwag i-apply nang walang utos niya.
- If John says it is for `/plan`: plan only, no edits, no delivery folders (the plan goes in the vault).
