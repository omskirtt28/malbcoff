# Malbcoff Trading — Phase 1 / Patch P1-001

This patch establishes the XAMPP-ready Phase 1 foundation and the approved UI/UX direction.

## Included
- Clean desktop-first UI matching the approved visual direction
- Owner and Branch User layouts
- Login + session authentication
- Role and branch-aware access
- 4-branch database foundation
- Brand → Model → Variant/Storage → Color structure
- Phone / Pre-Loved / Accessory product types
- Unique IMEI storage at database level
- Barcode-ready accessory stock
- Add New Item flow
- Inventory grouped by product with View Units modal
- Stock In history created automatically when items are added
- Stock Movement history
- Owner dashboard and Branch dashboard
- Offline-friendly icons/UI (no CDN required)

## Not yet included in this patch
- Bulk IMEI receiving UI
- Branch-to-branch transfer workflow
- Stock adjustment approval flow
- Returns / defective workflows
- POS / sales transactions (Phase 2)
- Advanced reporting

These will be separate patches so the system stays easy to test and maintain.

## UI reference files
The exact approved visual references are included under `design-reference/` so future patches can stay visually consistent.
