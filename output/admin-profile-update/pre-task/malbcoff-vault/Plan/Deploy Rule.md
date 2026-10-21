---
tags: [plan, deploy, rule]
updated: 2026-10-21
---
# Deploy Rule — "patch delivery" (permanent)

**Deployment status confirmed 2026-10-20:** Everything before the two inventory fixes in this task is **LIVE**, including the 2026-10-17 package and all earlier patches (including tablet login). Only this task is **done, not yet live**. Baseline is **v2**, confirmed from the last delivered package before any task edits. Earlier build-time pending statements are historical and superseded by this confirmation.

Ito ang permanenteng rule kung paano magbigay ng patch/deploy package. Nakasulat din sa `CLAUDE.md` at [[00 Start Here]]. Log ng bawat package: [[Deploy Package]].

## Trigger phrases (English or Taglish)
"akin na yung patch" · "give me the patch" · "ibigay mo yung patch" · "latest patch" · "deploy package" · "patch na" (at kahawig na salita).

## Kapag sinabi ng user ang trigger phrase
- **Huwag magtanong. Huwag i-re-read ang buong project.** Tumakbo agad:
  `powershell -NoProfile -ExecutionPolicy Bypass -File deploy\build-update-package.ps1`
  (`-DryRun` = listahan lang.) Ang script ang naghahambing ng SHA-256 ng bawat app file sa `deploy/baseline-manifest.json` (ang nasa LIVE ngayon). **Ang files lang na bago o iba ang hash ang isasama. Hindi isinasama ang nasa live na.**
- Bago patakbuhin: kung may manual na hakbang para sa package (config line sa live `config/app.local.php`, `.htaccess` block na kailangang i-merge, atbp.), isulat ang mga iyon sa `deploy/package-notes.txt` (plain English). Lalabas ito sa README bilang "EXTRA NOTES". Binubura ito ng `confirm-deployed.ps1`, kaya ang hindi pa na-confirm na package ay dala pa rin ang lumang notes.
- Output (Desktop): folder `malbcoff-deploy-update-<yyyy-mm-dd-hhmm>\` (parehong folder structure ng project root) + `.zip` na kapangalan (tunay na zip, forward-slash entries), `deploy/migrations.sql` (MGA BAGONG migration lang mula sa `database/migrations/` na wala sa `migrations_applied` ng baseline, original order, re-runnable) at `deploy/README.txt` (plain English: backup muna, run migrations.sql sa phpMyAdmin, upload na buo ang folders, test checklist, rollback, manual config lines, at "tablet clear cache" tip kapag nagbago ang service worker / login files).
- **Hindi kailanman isinasama:** vault, `CLAUDE.md`/`*.md`, `.git`, `node_modules`, backups, logs, uploads, caches, tests, `database/`, `deploy/` ng project, `design-reference/`, `preview.html`, `*.zip`/`*.sql`, `*.local.php`, `.env*`, at ibang deploy folders. Ang config lines na kailangan ay nasa README, hindi sa file. (Listahan: `$ExcludeDirs` / `$ExcludeFiles` sa `deploy/_common.ps1`.)
- **Pagtatapos ng sagot:** ilista ang new at changed files, ang zip path, at ang baseline version na ginamit. Sabihin: **"Reply 'deployed' after uploading so the baseline can be updated."** Huwag i-mark na deployed.

## Kapag sinabi ng user na "deployed" ("na-deploy ko na", "live na")
`powershell -NoProfile -ExecutionPolicy Bypass -File deploy\confirm-deployed.ps1`
- Inililipat ang baseline sa **eksaktong estado ng files sa huling package na na-deliver** (`deploy/last-package.json`, naka-save noong ginawa ang package) — hindi sa kasalukuyang project, kaya hindi nadadamay ang mga binago pagkatapos.
- Tinataasan ang `version` (v1 → v2 …), nilalagyan ng `confirmed_at` at `history`, idinadagdag ang mga migration sa `migrations_applied`, binubura ang `last-package.json` at `package-notes.txt`.
- Isulat ang petsa sa [[Deploy Rule]] (section "Baseline history" sa baba) at sa [[Changelog]].
- **Kapag hindi nag-confirm ang user:** hindi gumagalaw ang baseline, kaya ang susunod na package ay kasama pa rin ang lahat ng unconfirmed na pagbabago.

## Ang files ng tooling (sa `deploy/`)
| File | Git | Papel |
|---|---|---|
| `baseline-manifest.json` | **git-ignored**, di kasama sa package | `version`, `confirmed_at`, `files` (path → SHA-256 ng LIVE), `migrations_applied`, `history` |
| `last-package.json` | git-ignored | hashes ng huling na-deliver na package (para sa "deployed") |
| `package-notes.txt` | git-ignored | manual notes para sa README ng susunod na package |
| `build-update-package.ps1`, `confirm-deployed.ps1`, `_common.ps1` | tracked | ang mismong rule, nakasulat bilang script |

Kung nawala ang `baseline-manifest.json`: huminto at sabihin sa user (hindi manghuhula). Muling buuin ito gamit ang method sa ibaba.

## Paano ginawa ang unang baseline (v1, 2026-10-18)
Sabi ng user: live na ang lahat hanggang sa huling incremental package na na-deploy nila — ito ay **Package #2** (`malbcoff-deploy-update-2026-10-17.zip`). Ang tablet login fix at lahat ng sumunod ay HINDI pa live.
1. Files sa Package #2 → hash ng laman ng zip (45 app files). **Note:** ang `.zip` na iyon ay totoong *tar* file pala (hindi `PK`), kaya hindi mabuksan ng Windows/.NET; kinuha gamit ang `tar -x` sa temp folder sa labas ng project at kinumpirma na kapareho (0 diff) ng Desktop folder nito. Ang mga bagong package ay tunay na zip na.
2. Files na nasa Package #1 (listahan sa [[Deploy Package]]) pero wala sa #2 (14 files) → hash ng project ngayon, dahil napatunayan ng #2 (byte compare) at ng #3 na hindi sila nagbago pagkatapos. **Assumption:** na-deploy ang package #1. (Wala na ang `malbcoff-owner-updates-2026-10-17.zip` at ang `malbcoff-deploy` folder sa Desktop, kaya hindi na ma-hash ang mismong zip na iyon.)
3. Natitirang app files (74) na hindi nasa anumang package → ihinahambing sa git commit `021cbad` (ang inakalang live ni package #1). Pareho ang laman (kahit iba ang line endings, CRLF vs LF) = live (hash ng project). **Iba ang laman = hindi live** (`core/Security.php` lang, may tablet fix) → ang baseline hash ay hash ng `021cbad` version kaya ito ay isasama.
4. Walang file na hindi matukoy ang estado. (Kung meron, hindi ito ilalagay sa baseline para maisama — mas ligtas.)
5. `migrations_applied` = P3_001 hanggang P3_005 (nasa live sa Package #2).
Bilang: 45 (Package #2) + 14 (Package #1) + 74 (git `021cbad`: 6 identical, 67 line-ending lang, 1 may pagbabago = `core/Security.php`) = **133 files** sa baseline; lahat ay nasa project pa.

## Baseline history
- **v3 — 2026-10-21** — reconciled only nine files untouched by FIX 8C-2 plus P3_006, based on the user's explicit confirmation that all preceding work is LIVE. Kept every current FIX 8C-2 change pending. Manifest backup: `backups/baseline-before-fix8c2-package-v2.json`. Builder dry run now selects exactly the 12 FIX 8C-2 app files and P3_007. Three pre-existing files absent from v2 still classify NEW to tooling; they are hosting replacements per user confirmation. Exact pending delivery recorded in `deploy/last-package.json`: `Desktop/malbcoff-FIX8C2-only-2026-10-21-004916.zip`, 12 app files + only P3_007 + README, 14 hash-verified entries. Do not confirm this package until the user says deployed.
- **v1 — 2026-10-18** — estado ng Package #2 + mga hindi nagbagong lumang files (ayon sa user na live na). Hanggang P3_005.

- **v2 — 2026-10-20** — user confirms ALL work before this task LIVE. Ran confirm-deployed.ps1 before edits; applied the last-package hashes for package #4 (13 files), earlier migrations P3_001–P3_005 remain applied. This task and P3_006 remain **done, not yet live**.
