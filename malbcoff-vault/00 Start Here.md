---
tags: [index]
updated: 2026-10-18
---
# Malbcoff POS Vault — Start Here

Ito ang "memory" ng project. **Basahin muna ang notes dito bago magbasa ng code** para tipid sa usage.

## Paano gamitin with Claude Code
1. Sabihin: *"Read malbcoff-vault/00 Start Here.md and Plan/Owner Updates Plan.md, then do Patch N only."*
2. Claude Code reads lang yung files na naka-list sa patch note (hindi buong codebase).
3. Pagkatapos ng patch, i-update ang related Feature note + i-tick sa [[Owner Updates Plan]].

## Patch delivery rule (permanente) — tingnan [[Deploy Rule]]
- **Trigger phrases:** "akin na yung patch", "give me the patch", "ibigay mo yung patch", "latest patch", "deploy package", "patch na".
- Kapag sinabi ang alinman: **huwag magtanong, huwag i-re-read ang project** — patakbuhin ang `deploy\build-update-package.ps1`. Package = LANG files na bago/binago laban sa `deploy/baseline-manifest.json` (ang LIVE na). Output sa Desktop: `malbcoff-deploy-update-<yyyy-mm-dd-hhmm>\` + zip, `deploy/migrations.sql` (bagong migrations lang), `deploy/README.txt` (plain English).
- Tapusin sa listahan ng new/changed files, zip path, baseline version, at: **reply "deployed" after uploading**.
- Kapag "deployed" / "na-deploy ko na" / "live na": `deploy\confirm-deployed.ps1`, tapos itala sa [[Deploy Rule]] at [[Changelog]]. Walang confirmation = hindi gumagalaw ang baseline.

## Map
- [[Architecture Map]] — paano dumadaan ang request, folders, layers
- [[File Index]] — bawat file at ano ginagawa
- [[Data Model]] — tables, columns, movement types
- [[Roles and Access]] — sino pwede saan
- [[Conventions]] — rules na dapat sundin sa bawat patch
- [[Migrations]] — SQL migration files (`database/migrations/`)

## Features
- [[Dashboard]] · [[Stock Monitoring]] · [[Sales Records]] · [[Stock Movement]]
- [[Stock In (Receive Stock)]] · [[Branch Transfers]] · [[POS]] · [[Inventory]]
- [[Users and Accounts]] · [[Auth and Session]] · [[Service Worker and Offline Page]]

## Current work
- [[Owner Updates Plan]] — 7 patches, naka-order (Patch 7 done: [[Patch 7 - English UI and Stock Monitoring Tabs]])
- [[Patch 8 - Price at Checkout]] — typed prices at checkout, Set Prices sheet, sales are final
- [[Patch 8B - Checkout Modal Polish]] — Set Prices layout polish + compact "Sale saved" state
- [[Patch 6c-1 - Offline Outbox for Stock Actions]] — Receive Stock / Branch Transfer / Receive Transfer queue on the device and send when online; owner Needs Action list
- [[Patch 6c-2 - Offline Sales Outbox]] — POS sales saved on the device when the net drops, sent once later (client id, device time, "In a queued sale", owner Needs Action for refused sales); migration `P3_005`
- [[How to turn off the service worker]] — emergency switch for the Patch 6b service worker
- [[Deploy Rule]] — permanenteng patch-delivery rule (trigger phrases, baseline manifest, "deployed")
- [[Deploy Package]] — ano ang laman ng bawat live-deploy zip
- [[BUG Stock Monitoring Owner Branch View]] — root cause
- [[BUG Tablet Login Stays on Login Page]] — tablets returned to login (service worker re-sent pages with another User-Agent); fixed 2026-10-18

## Source docs (nasa root, mahaba — basahin lang kung kailangan)
`ARCHITECTURE.md`, `SCHEMA.md`, `RULES.md`, `PRD.md`, `DESIGN.md`

**Design rules (basahin bago mag-UI patch):** `DESIGN.md` §15 "App Rules" + checklist "Before finishing any patch" — English-only UI, 360/768/desktop, 44px, 14px, tabs/table pattern. See [[Conventions]] and [[Patch 7 - English UI and Stock Monitoring Tabs]].
