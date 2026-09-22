# Malbcoff Trading POS & Inventory System — Development & Business Rules

This file is the operating rulebook for all future patches. New code must preserve these rules unless a new approved requirement explicitly changes one.

---

## 1. Documentation Priority

When documents conflict, use this order:

1. Approved current business requirement
2. `RULES.md` — behavioral and development constraints
3. `PRD.md` — product scope and required capabilities
4. `SCHEMA.md` — data model contract
5. `ARCHITECTURE.md` — technical structure
6. `DESIGN.md` — UI/UX implementation rules
7. Existing legacy behavior not covered above

Any deliberate rule change must update all affected documentation in the same patch.

---

## 2. General Engineering Rules

- Use PHP + MySQL/MariaDB + HTML/CSS/vanilla JavaScript unless a stack change is explicitly approved.
- Keep the system XAMPP-compatible during local development.
- Use `Asia/Manila` for application timestamps unless a future requirement introduces multi-timezone support.
- Use English labels in the application UI.
- Do not introduce CDN dependencies for critical operation.
- Do not expose database credentials, API secrets, stack traces, or raw SQL errors to end users.
- Do not hardcode localhost-only URLs into production-facing business logic.
- Use UTF-8 / `utf8mb4` for database text.
- Prefer small, reviewable patches over large unrelated rewrites.

---

## 3. Authentication and Session Rules

- Only `users.is_active = 1` may log in.
- Passwords must never be stored in plaintext.
- Successful login must regenerate the session ID.
- Mutating forms/endpoints require CSRF validation.
- Logout must destroy the current session.
- Future session timeout/single-session features must not weaken CSRF/role controls.

---

## 4. Role Rules

### Owner

- Global branch visibility.
- May select a branch scope when needed.
- May process POS for a selected branch.
- May receive stock to a selected branch.
- May control protected costs and all branch selling prices.
- May perform the current serialized inventory adjustment workflow.
- May see all users and stock movement history.

### Branch Manager

- Restricted to assigned branch.
- May use POS.
- May receive stock.
- May view branch inventory/movements/users.
- May update only the assigned branch selling price where enabled.
- Must not perform Owner-only serialized stock removal.
- Must not access another branch by editing URL/form values.

### Inventory Staff

- Restricted to assigned branch.
- May receive stock and view inventory/movement history.
- No POS under current role rules.
- No Owner-only cost/adjustment controls.

### Cashier

- Restricted to assigned branch.
- May use POS.
- No stock receiving or stock movement administration under current rules.
- Must not see protected acquisition cost/profit data.

---

## 5. Branch Scope Rules

- For non-owner users, the authenticated `users.branch_id` is authoritative.
- Ignore/reject any posted branch attempting to override a non-owner's assigned branch.
- Owner branch selection is allowed only after validating that the branch exists and is active.
- All branch-sensitive database queries must include branch filtering server-side.
- A UI branch selector is never a security boundary.

---

## 6. Catalog Rules

### Brands and models

- Brand names must be normalized consistently.
- Models belong to exactly one brand.
- Phone/tablet models may carry `device_type`.
- Archived/inactive parent entities must block creation/restoration of active children unless intentionally restored first.

### Variants

A phone/tablet variant is considered duplicate when the same product type, brand, model, and normalized configuration already exists.

Relevant configuration fields:

- RAM
- Storage
- Connectivity for tablets
- Color

Current normalization intent:

- Storage/RAM capacity uses consistent GB/TB formatting.
- Color is normalized consistently.
- Apple variants may omit RAM under the current rule.
- Tablet connectivity must be `Wi-Fi` or `Wi-Fi + Cellular` unless expanded later.

### Archive/delete

- Active variant with available stock cannot be archived.
- Restore requires active parent brand/model.
- Physical delete is allowed only if there are no unit, movement, or sales references.
- If history exists, retain the record and use catalog soft-delete fields instead of breaking references.

---

## 7. Serialized Device Rules

Phones/tablets use `inventory_units`.

- One physical device = one `inventory_units` row.
- Serialized unit quantity is always 1.
- Unit must belong to one branch at a time.
- A unit cannot be sold unless status is `available`.
- Sold units remain stored for history.
- Adjusted/defective/returned units remain stored for history.

### Identifier rules

Server normalization and duplicate checks are mandatory.

Current receiving behavior indicates:

- Apple device detection uses brand/model rules and expects serial-oriented handling.
- Non-Apple phones require IMEI-oriented handling.
- Cellular tablets require IMEI-oriented handling.
- Wi-Fi tablets use serial number handling.
- Dual-SIM/dual-IMEI support may store `imei` and `imei2`.
- A duplicate may not be inserted merely because it was scanned by OCR.

Exact identifier logic in `actions/stock_in.php` remains authoritative until deliberately refactored.

---

## 8. Accessory Rules

Accessories use `inventory_balances` rather than one row per physical unit.

- One branch/product balance row should represent quantity on hand.
- Quantity must never become negative.
- POS decrement must use a guarded update (`quantity >= requested`).
- Receiving increments the same branch/product balance.
- Barcode must be treated as a lookup identifier, not as permission to change stock.

---

## 9. Condition Rules

Current application code distinguishes condition separately from product type.

- `brand_new` devices are eligible for current POS.
- `preloved` devices are excluded from current POS until dedicated requirements are implemented.
- Historical `condition_grade` / `battery_health` may remain for pre-loved workflows.
- Do not infer condition solely from product catalog type if `inventory_units.condition_type` is available.

---

## 10. Pricing and Cost Rules

- Selling price is resolved per branch using `branch_product_prices` when available.
- Product-level `selling_price` acts only as fallback where current code permits.
- POS must re-read the effective server-side selling price during sale completion.
- Never accept a JavaScript/cart price as authoritative.
- Cost is protected and must be restricted by role.
- Owner may manage cost.
- Branch Manager may manage only the assigned branch selling price under current behavior.
- Inventory Staff and Cashier must not gain cost update permission through UI or crafted requests.
- Price and cost values must be non-negative decimal values.

---

## 11. Receive Stock Rules

- Allowed roles: Owner, Branch Manager, Inventory Staff.
- Branch is validated server-side.
- Product must be active.
- Missing schema must fail safely; do not partially insert.
- Selling price must be valid for the receiving branch before stock is accepted where current flow requires it.
- Device identifiers must be validated for duplicate/conflict before insert.
- Accessory receive must update balance atomically.
- A successful receive creates stock movement history and a reference number.
- Notes must be trimmed/normalized and length-limited.

### Restore of adjusted-out units

- Restoration is not a new unit creation.
- Only eligible adjusted-out units may be restored.
- Match must satisfy model/product type rules and branch restrictions in the current flow.
- IMEI 2 alone must not be allowed to incorrectly restore the wrong primary unit.
- Restoration must leave an auditable movement trail.

---

## 12. POS Rules

- Allowed roles: Owner, Branch Manager, Cashier.
- Current sellable scope: brand-new phone/tablet units + accessories.
- Pre-loved is not sellable through current POS.
- Cart maximum is currently 100 lines unless deliberately changed.
- A serialized `unit_id` may appear only once per sale.
- The same accessory `product_id` should be one cart line with quantity.
- Device must belong to the sale branch.
- Device must still be `available` at commit time.
- Accessory balance must still be sufficient at commit time.
- Total is recalculated server-side.
- Current total = subtotal; no hidden discount logic.

### Payment rules

Allowed current methods:

- `cash`
- `gcash`
- `maya`
- `card`
- `bank_transfer`

Cash:

- `amount_received >= total`
- `change_due = amount_received - total`

Non-cash:

- Payment reference may be stored according to workflow.
- Do not fabricate a cash change amount.

### Sale completion transaction

All steps must be one transaction:

1. Validate/lock branch.
2. Re-resolve every cart line.
3. Re-resolve selling price and stock.
4. Calculate subtotal/total.
5. Insert `sales`.
6. Update exact device status or accessory balance.
7. Insert `sale_items` snapshots.
8. Insert `stock_movements`.
9. Commit.

Any failure rolls back everything.

---

## 13. Stock Movement Rules

Every inventory-changing event needs a movement record.

Use movement types consistently:

- `stock_in`
- `stock_out`
- `sale`
- `transfer_in`
- `transfer_out`
- `adjustment`
- `return`
- `defective`

Quantity sign convention:

- Positive = inventory enters availability/location
- Negative = inventory leaves availability/location

A serialized movement should reference `unit_id` whenever possible.

Use reference numbers for business events so related rows can be grouped.

---

## 14. Inventory Adjustment Rules

Current serialized-unit adjustment is Owner-only.

Allowed current reasons:

- Stock Correction
- Damaged
- Missing
- Return to Supplier
- Other

Rules:

- Only `available` units may be adjusted by the current endpoint.
- Max selection is currently 100 units per request.
- `Other` requires a note.
- The unit record is retained.
- Status changes to an appropriate non-available status.
- A negative `adjustment` movement is recorded.
- The action uses a transaction and guarded update.

---

## 15. Sales History Rules

When sales history/receipt UI is added:

- Never reconstruct historical item prices from current catalog price.
- Use `sale_items` snapshots.
- Never change a past sale just because product name/spec/price changed later.
- Reprint must reuse the existing `sale_no`.
- Voids/refunds, when implemented, require dedicated status/movement records; do not simply delete sales.

---

## 16. Database Rules

- InnoDB for transactional tables.
- Foreign keys where historical behavior allows it.
- Use `DECIMAL`, not floating-point database types, for currency.
- Use unique constraints for business identifiers when possible.
- Add indexes for frequent branch/status/date/product/identifier searches.
- Migrations must be idempotent only when intentionally designed; otherwise version/order must be explicit.
- Never rely solely on runtime `SHOW COLUMNS/TABLES` checks as a replacement for migrations.
- Back up production database before destructive or structural migrations.

---

## 17. PHP Rules

- `declare(strict_types=1)` may be introduced consistently in a planned cleanup; do not mix carelessly in legacy files without testing.
- Use prepared PDO statements.
- Escape output with `e()` unless intentionally rendering trusted internal SVG/helper output.
- Validate integers with `FILTER_VALIDATE_INT` or equivalent.
- Normalize text length before persistence.
- Return safe user messages; log detailed exceptions server-side in production.
- Use `RuntimeException` or domain exceptions for expected business validation failures.
- Do not suppress important database failures silently in write paths.

---

## 18. JavaScript Rules

- JS is an enhancement, not the final authority for business rules.
- Never trust hidden fields/cart JSON for price, branch permission, or stock state.
- Avoid introducing framework dependencies for small UI behavior without approval.
- Scanner/OCR failure must permit manual entry.
- Avoid duplicate event bindings after partial UI updates.
- Confirm destructive actions clearly.

---

## 19. UI Rules

- Follow `DESIGN.md` and current CSS tokens.
- English labels only.
- Keep branch context visible.
- Do not show inaccessible actions and then rely on an error after click.
- Cost/profit must not leak through UI, HTML attributes, JSON, or hidden fields to unauthorized roles.
- Empty states should explain what to do next.
- Keep forms readable; do not compress fields into dense layouts merely to fit one screen.

---

## 20. Migration/Patch Rules

Every schema-changing patch must include:

1. Migration SQL file
2. Upgrade instructions
3. Backward/forward impact note
4. Data migration if existing rows require defaults/backfill
5. Rollback strategy when practical
6. Clean-install verification
7. Upgrade-from-previous-version verification

Recommended naming:

```text
database/migrations/
P2_010_sales_history.sql
P2_011_branch_transfer.sql
```

Do not ship PHP that references a column/table absent from the patch package.

---

## 21. Definition of Done

A code patch is not complete until:

- PHP syntax checks pass.
- Required SQL migrations are included.
- Owner workflow is tested.
- Relevant branch roles are tested.
- Unauthorized role and cross-branch attempts are tested.
- CSRF failure is tested for mutations.
- Concurrency-sensitive stock operation is tested.
- Stock movement/audit result is verified.
- No duplicate IMEI/serial path was introduced.
- UI matches current design system.
- Documentation is updated when rules/schema/scope change.
