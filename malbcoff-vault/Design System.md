---
tags: [design, rules]
updated: 2026-10-21
---
# Design System

Canonical implementation rules are in project-root `DESIGN.md`, especially section 15 and the Stock Monitoring scale. Read it before UI changes.

## Added 2026-10-20 — done, not yet live
- Stock availability chips: server-rendered links, All / In stock / Out of stock, 44px minimum height, 14px semibold, blue active background/border, visible focus, URL state preserved with other filters/tabs/export.
- Out of stock: numeric 0 stays right-aligned with a plain-English label in muted red #a54747, 14px regular, no extra data column.
- Deleted Inventory Units: same card/header/table style as existing Deleted Records; search/branch/date controls and 25-row pagination. At 360px use labelled unit cards; at 768/desktop retain the table. Labels and copy 14px; buttons and checkbox label targets >=44px.
- Permanent inventory dialog: native modal dialog (focus trap/Escape), exact unit list + count, bulk requires typed DELETE. Neutral Cancel + red Delete permanently. Shared SafeSubmit states and retry protection; no custom second save-state system.
- Archived (history kept): only units with sale items or voids; display “This unit has sales history, so it is kept for records. Its IMEI can be used again.” No-sales archived rows show checkbox/Delete permanently. Database References pills use a compact 2x2 grid, labels never wrap; labels/counts >=14px and buttons >=44px. Delete all with no sales previews the global eligible count and requires DELETE.

Components: `assets/css/app.css`, `assets/js/deleted-inventory-units.js`, `partials/deleted-inventory-units.php`, `pages/stock-monitoring.php`. Verified at 360 / 768 / 1280, no horizontal page scroll. Existing wide stock table can scroll inside its wrapper.

2026-10-21: all components before FIX 8C-2 are LIVE per user confirmation. FIX 8C-2 is done, not yet live. Transfer snapshots show Unit deleted (>=14px); their long product/identifier labels wrap inside the detail panel. Branch Transfers tabs stay within the 360px viewport. See [[Deploy Package]].
