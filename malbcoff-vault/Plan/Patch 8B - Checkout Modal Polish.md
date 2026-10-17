---
tags: [plan, patch, pos, ui]
---
# Patch 8B — Checkout modal polish
**Status: DONE 2026-10-17.** Visual and copy only, English UI. Builds on [[Patch 8 - Price at Checkout]]. Feature note: [[POS]]. No server, schema or price logic change.

## A) Set Prices (`#posPriceSheet`, rows built in `pos.js` `renderPriceRows`)
- Input box: 44px tall, `₱` fixed on the left (12px padding + 8px gap, centered), number 18px / 600, left aligned, no inner padding so the caret never touches the symbol or border. Box shows the focus ring (the inner input outline is off).
- Placeholder `0.00`: 14px, regular, muted gray (#98a2b3) — never looks like a value. `inputmode="decimal"`; the existing value is selected on focus (`focusin`, also the "Same price for all" field).
- **Line total** is its own row under the input (8px gap, `.price-field`): label 12px muted, amount 14px / 600; muted regular dash when no price. The "3 × ₱50.00" / "₱33.33 each" detail sits under it (14px, hidden when empty).
- Row card: 16px padding, 12px gaps, toggle 44px / 14px, title 15px / 600. Footer: Grand Total 22px + Confirm Sale 48px, 12px padding, still sticky (flex column, body scrolls).

## B) "Complete this transaction?" + success
- Before confirming: Branch / Items / Payment / Total unchanged, spacing 16px padding, 12px row gaps, 14px text, 44px buttons, 44px × button. Buttons: Confirm & Complete Sale + Review Cart.
- After the sale is saved (`showSaleDone` in `pos.js`): `.pos-confirm-dialog.is-done` hides header / body / buttons; `.pos-done` shows a 24px check, "Sale saved", "Sale no. …", and rows Total / Payment / Cash received / Change (label left, amount right, `nowrap`). Cash received + Change rows are hidden for non-cash payments. Cash received = the typed cash field (the server reply has no such field).
- One primary button: **New Sale** (clears the cart from `sessionStorage`, reloads). No "View Receipt" — there is no receipt page in `pages/` or `index.php`. No OK / Saved / Review Cart in this state.
- `SafeSubmit.run` got an opt-in `quietSuccess` (no green card, no OK; calls `onSuccess`). Other forms are unchanged.
- Double tap: `saleSaving` stays true after success and the client key is the same, so no second sale; Escape / backdrop / × can no longer close the modal after success.

## Checks (headless Chrome, real `app.css` + `pos.js` + `safe-submit.js` in a harness, `fetch` stubbed; widths via a fixed-width iframe)
360 / 768 / 1280 × single item, 3 mixed lines (long name), large (₱100,000.00 total, cash ₱150,000.00, Change ₱50,000.00) × Set Prices / confirm / saved:
- [x] no page or panel horizontal overflow, amounts on one line, all buttons and the input box ≥44px.
- [x] input box 44px, 18px / 600, ₱ sits 13px from the box edge, 8px to the number.
- [x] three rapid taps on Confirm → one request.
- [x] GCash path hides Cash received / Change.
- Only text under 14px is the 12px "Line total" label, as specified for this patch.
- Not exercised: a real sale against the database and a real phone keyboard (no login used; nothing was written).

## Files
Changed: `pages/pos.php`, `assets/js/pos.js`, `assets/js/safe-submit.js`, `assets/css/app.css` (edits in the Patch 8 block + a "Patch 8B" block at the end).

Related: [[Conventions]] · [[Deploy Package]]
