---
tags: [plan]
status: ready-to-patch
updated: 2026-10-16
---
# Owner Updates Plan

Gawin **isa-isang patch**, in order. Bawat patch: basahin lang ang "Files" nito + [[Conventions]]. Pagkatapos: i-test ang "Acceptance", i-update ang Feature note.

| # | Patch | Size | Depends on |
|---|---|---|---|
| 1 ✅ | [[Patch 1 - Fix Stock Monitoring Owner View]] — [x] done 2026-10-16 | M | — |
| 2 ✅ | [[Patch 2 - Stock In and Transfer Separate]] — [x] done 2026-10-17 | M | 1 |
| 2B ✅ | [[Patch 2B - Clickable KPI Drawer]] — [x] done 2026-10-17 | M | 2 |
| 2C ✅ | [[Patch 2C - Simpler Stock Monitoring Table]] — [x] done 2026-10-17 | S | 2, 3 |
| 3 ✅ | [[Patch 3 - Units and Accessories Sold Separate]] — [x] done 2026-10-17 | M | 1 |
| 4 ✅ | [[Patch 4 - Sold Today Clickable]] — [x] done 2026-10-17 (as Dashboard KPI drawers, see note) | S | 3 |
| 5 ✅ | [[Patch 5 - Edit Account]] — [x] done 2026-10-17 (own display name only) | S-M | — (pwede parallel) |
| 6a ✅ | [[Patch 6a - Weak Internet Safe Retry]] — [x] done 2026-10-17 | M | — |
| 7 ✅ | [[Patch 7 - English UI and Stock Monitoring Tabs]] — [x] done 2026-10-17 (English UI, Phones & Tablets / Accessories tabs, design rules) | M | 2C |
| 8 ✅ | [[Patch 8 - Price at Checkout]] — [x] done 2026-10-17 (Set Prices sheet at checkout, typed prices, completed sales final) | M | 6a |
| 6b ✅ | [[Patch 6b - Offline Shell and Cache]] — [x] done 2026-10-17 (service worker for static files only, offline page, install manifest, banner states; switch: [[How to turn off the service worker]]) | S-M | 6a |
| 6c-1 ✅ | [[Patch 6c-1 - Offline Outbox for Stock Actions]] — [x] done 2026-10-17 (Receive Stock / Branch Transfer / Receive Transfer saved on the device and sent when online, Needs Action for the owner; **not sales**) | L | 6a, 6b |
| 6c-2 ✅ | [[Patch 6c-2 - Offline Sales Outbox]] — [x] done 2026-10-17 (POS sales saved on the device when the net drops, sent once later; "In a queued sale"; refused sales go to the owner's Needs Action; migration P3_005) | L | 6c-1, 8 |

## Mga assumption (sabihin kung mali, para ma-adjust)
1. "Stock In & Transfer separate" = sa Owner views, hiwalay ang **Stock In (galing supplier)** sa **Transfer In / Transfer Out (branch-to-branch)** — sa Stock Monitoring, Dashboard, at Stock Movement tabs. Ngayon pinagsama sa "IN".
2. "Units vs accessories sold" = hiwalay na bilang/₱ ng phones+tablets (units) at accessories sa Dashboard, Sales Records, at Stock Monitoring.
3. "Sold Today need click" = ang card (Dashboard "Sales Today" at Stock Monitoring "Sold Today") ay link papunta sa listahan ng benta ngayon (filtered).
4. "Edit account" = (a) Owner edit ng users (existing na — i-verify/ayusin) + (b) bawat user may **My Account** para i-edit ang sariling name/email.
5. "Kahit mahina net" = hindi nawawala/hindi doble ang benta at stock-in kapag mabagal o putol ang net; hindi full-offline POS maliban kung gawin ang optional 6c.

## Order rationale
Bug muna (1) kasi ang 2-4 ay gumagamit ng bagong report shape. 5 independent. 6 last kasi sakop nito ang POS/stock-in forms na hindi dapat gumagalaw habang nag-aayos ng reports.

## Definition of done (bawat patch)
- Owner at branch user views gumagana pa (Conventions).
- No PHP warnings (`display_errors` on locally).
- Migration file kung may schema change.
- Feature note updated + tick dito.
