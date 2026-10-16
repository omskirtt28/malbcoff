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
| 2 | [[Patch 2 - Stock In and Transfer Separate]] | M | 1 |
| 3 | [[Patch 3 - Units and Accessories Sold Separate]] | M | 1 |
| 4 | [[Patch 4 - Sold Today Clickable]] | S | 3 |
| 5 | [[Patch 5 - Edit Account]] | S-M | — (pwede parallel) |
| 6a | [[Patch 6a - Weak Internet Safe Retry]] | M | — |
| 6b | [[Patch 6b - Offline Shell and Cache]] | S-M | 6a |
| 6c | [[Patch 6c - Offline Sale Queue]] | L (optional) | 6a, 6b |

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
