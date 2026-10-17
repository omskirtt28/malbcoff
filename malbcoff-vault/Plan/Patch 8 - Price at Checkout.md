---
tags: [plan, patch, pos]
---
# Patch 8 — Price at Checkout
**Status: DONE 2026-10-17.** Items had a 1 peso placeholder price. Each branch sells at its own negotiated price, so the cashier now types the agreed price for every line right before the sale is final. Feature note: [[POS]]. Schema: [[Migrations]] `P3_003`.

## Flow (`pages/pos.php` + `assets/js/pos.js`)
1. Cart no longer shows a price: lines say "Price set at checkout", Subtotal/Total show "—", the button says **Checkout**. Search results say "Price set at checkout". The 1 peso placeholder is never displayed or sent.
2. **Checkout** → **Set Prices** sheet (`#posPriceSheet`): full-height bottom sheet ≤700px, centered 640px panel above. One row per cart line: name, qty, **Per piece / Total for all** toggle, big numeric input (`inputmode="decimal"`, 24px), "Last: ₱…" chip, live line total, red "Enter price" (shown on empty rows you are not typing in).
3. **Total for all** stores the typed total as the truth; per-piece = total ÷ qty rounded (10 for 500 → 50.00; 3 for 100 → 33.33 each, line stays 100.00). Switching modes keeps the same money (per piece → total exact; total → per piece only if it divides evenly, otherwise the field is cleared).
4. **Same price for all** (shown with 2+ lines): one per-piece price applied to every row, rows stay editable.
5. **Last chip**: `actions/pos_last_prices.php` returns, per product, the per-piece price **this branch** last sold it at (completed, not voided, price typed at checkout). Branch comes from the login only — `branch_id` in the URL is ignored. Old sales (no `price_entry_mode`) are not history. Chip hidden when there is none. Tap fills the input (× qty in Total mode).
6. **Enter** / Next moves to the next row (last row → Confirm Sale); first empty row is focused on open; Escape/× closes and returns focus to the barcode box.
7. Footer: **Grand Total** + **Confirm Sale** — disabled until every line has a price above 0.
8. After Confirm Sale the existing flow is unchanged: cash check (if cash is too low: red hint + cash field focused; after typing cash, **Checkout** goes straight to the confirm dialog without asking prices again) → "Complete this transaction?" → `SafeSubmit` (6a) → green saved card.
9. No cost comparison / below-cost warning.

## Server (`actions/complete_sale.php`)
- Each cart line must carry `price_mode` (`piece`|`total`) and `price` (string). Parsed to whole cents (`^\d{1,8}(\.\d{1,2})?$`, commas allowed). Empty / missing / non-numeric / 0 / negative / 3 decimals / `1e3` / unknown mode / above ₱99,999,999.99 → **422** with a plain message, nothing saved. No fallback to catalog or placeholder price (catalog price join removed from the sale queries).
- Server computes line total, per-piece price, grand total and change check itself in integer cents. `unit_price`/`line_total`/`total` sent by the browser are ignored.
- Saved per line: `unit_price` (computed), `line_total` (truth), `price_entry_mode`. `stock_movements.unit_selling_price` = the typed per-piece price. If migration P3_003 is not imported yet, the sale still saves (without the mode).
- Idempotency (6a): the typed prices are part of `cart_json`, so the request fingerprint includes them: same key + same prices → replay of the first sale; same key + different price → 409.

## Completed sales are final
- DB trigger `trg_sale_items_price_final` (BEFORE UPDATE on `sale_items`) refuses any change to `quantity`, `unit_price`, `line_total` or `price_entry_mode` — for everybody, owner included, and for any future code. Verified: owner-level `root` updates are refused too.
- There is no price-edit screen or endpoint (checked: nothing in `actions/` or `pages/` updates `sale_items` prices). Voiding a line does not edit it (`inventory_movement_voids`).
- Needs the TRIGGER privilege (same as P3_001). Without it the lock exists only because no code edits prices.

## Reports
Sales Records, Dashboard, Stock Monitoring "Sold" values and the KPI drawer already sum `sale_items.line_total` → they use the saved line totals; no change needed. Old rows keep their old `unit_price`/`line_total` (mode NULL).

## Weak internet
The typed prices live in the cart (`sessionStorage`) and travel inside the sale payload (`price_mode` + `price` per line), so the future outbox ([[Patch 6c - Offline Sale Queue]]) can carry them. Connection drop → 6a "Not saved yet." card + 6b banner; prices stay; Retry sends the same key and the same prices → one sale. No outbox built here.

## Files
New: `actions/pos_last_prices.php`, `database/migrations/P3_003_price_at_checkout.sql`.
Changed: `actions/complete_sale.php`, `pages/pos.php`, `assets/js/pos.js`, `assets/css/app.css` (Patch 8 block at the end), `DESIGN.md` §15.9.

## Acceptance (real Chrome via DevTools protocol at 360 / 768 / 1280px, 77 checks each, DB restored afterwards)
- [x] Barcode scan adds the item, scanning again raises qty, box stays focused; cart never shows ₱1.00.
- [x] Single item, multiple items, Total for all (10 for 500 → 50.00; 3 for 100 → 33.33 / 100.00), Same price for all, Last chip (per branch; Branch 2 user gets none; `branch_id` tampering ignored), missing/0 price blocks Confirm, Enter/Next order, commas, 2-decimal cut.
- [x] Triple tap on Confirm → one request, one sale; saved lines + sale total + change + movement price correct.
- [x] Server rejects: no price fields, empty, 0, 0.00 total, negative, text, 3 decimals, 1e3, unknown/missing mode, absurdly high (all 422, no sale); sneaky `unit_price`/`line_total`/`total` ignored; "1,250.50" accepted and total recalculated.
- [x] Database refuses UPDATE of `line_total`, `unit_price`, `quantity`, `price_entry_mode` on a saved line; harmless updates still work; no PHP updates sale prices.
- [x] Offline at the confirm dialog → red card + banner, prices kept; Retry after reconnect → one sale with the same prices. Same key twice → replay; same key with another price → 409.
- [x] Layout: no horizontal scroll, all text ≥14px, all buttons/inputs ≥44px; phone = full-height bottom sheet, 768/desktop = centered panel.
- Not exercised: real phone keyboards; the old Patch 6a test scripts (they assume the pre-Patch 8 one-tap checkout).

Related: [[Patch 6a - Weak Internet Safe Retry]] · [[Conventions]] · [[Deploy Package]]
