# Malbcoff Trading POS & Inventory System — Product Requirements Document

**Product:** Malbcoff Trading POS & Inventory System  
**Current phase:** Phase 2 in progress  
**Primary users:** Owner, Branch Manager, Inventory Staff, Cashier  
**Current branch model:** 4-branch foundation, designed to remain branch-extensible

---

## 1. Product Vision

Provide Malbcoff Trading with one reliable system for product setup, branch inventory, serialized phone/tablet tracking, accessory quantities, stock receiving, sales processing, pricing, and stock history.

The system should replace fragmented/manual tracking with a branch-aware source of truth where a specific device can be followed by IMEI/serial and every sale or stock change can be traced to a user and reference number.

---

## 2. Problems to Solve

1. Inventory is difficult to reconcile when serialized devices and bulk accessories are handled the same way.
2. Duplicate IMEI/serial entries can create false stock.
3. Branch users must not accidentally see or change another branch's stock.
4. Selling prices may vary by branch while costs need tighter visibility/control.
5. POS sales must immediately reduce the exact stock that was sold.
6. Multiple staff can act at the same time, creating risk of double-selling.
7. Stock corrections must be traceable instead of silently deleting records.
8. Product variants need structured setup so catalog data stays consistent.

---

## 3. Personas

### Owner

Needs a complete view across branches, protected cost/profit visibility, product/price control, stock corrections, and oversight of users and activity.

### Branch Manager

Needs to manage the assigned branch's operations, receive stock, use POS, view stock activity, manage branch selling prices within allowed rules, and view branch users.

### Inventory Staff

Needs fast product lookup, stock receiving, serialized identifier capture, inventory visibility, and stock movement history for the assigned branch.

### Cashier

Needs a fast POS focused on available branch stock, correct selling prices, supported payment methods, and clear sale completion. Cashier should not need inventory maintenance or cost access.

---

## 4. Product Goals

### G1 — Accurate inventory

Every available serialized device and every accessory quantity must reflect real branch stock.

### G2 — Traceability

Every stock change and sale must identify branch, item, quantity/unit, user, date/time, and reference where applicable.

### G3 — Safe branch operations

A non-owner user must be unable to operate outside their assigned branch even by manually modifying form values or URLs.

### G4 — Fast sales

Cashier/manager should be able to find an item, add it to cart, select payment, and complete a sale without navigating away from POS.

### G5 — Maintainable product catalog

Brands, models, variants, prices, and archived records should remain manageable without damaging transaction history.

---

## 5. Non-Goals for the Current Phase

Unless separately approved, the current baseline does not require:

- Full accounting/general ledger
- Supplier purchasing and accounts payable
- Employee payroll
- E-commerce storefront
- Loyalty points
- Advanced promotion engine
- Customer CRM
- Multi-currency
- Automatic government tax filing
- Complete transfer/return/defective workflows beyond the existing partial foundations

---

## 6. Functional Requirements

### AUTH — Authentication and Access

**AUTH-001** Users must sign in using an active account.  
**AUTH-002** Password validation must use secure password hashing.  
**AUTH-003** Login must regenerate the session ID.  
**AUTH-004** Mutating requests must require a valid CSRF token.  
**AUTH-005** Inactive accounts must not authenticate.  
**AUTH-006** Non-owner users must be restricted to their assigned branch.  
**AUTH-007** Navigation must hide modules unavailable to the current role.  
**AUTH-008** Server-side authorization remains required even when a control is hidden in the UI.

### CAT — Product Catalog / Product Master

**CAT-001** The system must maintain brands.  
**CAT-002** The system must maintain models under a brand.  
**CAT-003** Models must identify device type where needed (`phone` or `tablet`).  
**CAT-004** The system must maintain phone/tablet variants by relevant configuration.  
**CAT-005** Variant uniqueness must prevent duplicate active configurations.  
**CAT-006** Tablet variants must support connectivity such as Wi-Fi and Wi-Fi + Cellular.  
**CAT-007** Accessories must support category, product name, barcode, cost, selling price, and stock threshold as applicable.  
**CAT-008** Products/variants with history must not be destructively deleted in a way that breaks audit records.  
**CAT-009** Archived items may be restored if their parent brand/model is active.  
**CAT-010** The system must support local brand logos with a text fallback.

### PRICE — Pricing

**PRICE-001** The system must support branch-specific selling prices.  
**PRICE-002** Owner may manage selling prices across branches.  
**PRICE-003** Branch Manager may manage the assigned branch selling price where allowed.  
**PRICE-004** POS must always resolve the effective price server-side.  
**PRICE-005** Client/cart price values must never be authoritative.  
**PRICE-006** Cost visibility/update must remain restricted to authorized roles.

### INV — Inventory

**INV-001** Phones/tablets must be tracked as physical serialized units.  
**INV-002** Accessories must be tracked as branch quantities.  
**INV-003** Serialized identifiers must be unique according to the identifier rules in `RULES.md`.  
**INV-004** Device records must keep branch and status.  
**INV-005** Inventory views must support branch scoping.  
**INV-006** Inventory must display useful product specs and available quantities/units.  
**INV-007** Low-stock status must be supported for quantity-tracked products.  
**INV-008** A unit sold or removed from availability must remain in history.  
**INV-009** Owner may perform authorized serialized-unit adjustments with a reason.  
**INV-010** Inventory adjustment must never silently delete the physical-unit record.

### REC — Receive Stock

**REC-001** Owner, Branch Manager, and Inventory Staff may receive stock.  
**REC-002** A non-owner receives stock only into the assigned branch.  
**REC-003** Owner must explicitly choose the receiving branch when necessary.  
**REC-004** Receive Stock must support existing variants.  
**REC-005** Authorized users may create a missing variant from the receiving workflow where supported.  
**REC-006** Device stock must validate required identifier type before insert.  
**REC-007** Duplicate identifier checks must happen server-side.  
**REC-008** Adjusted-out units may only be restored when the server confirms the same allowed model/branch relationship.  
**REC-009** Accessory receipt must increment the correct branch balance.  
**REC-010** Every receipt/restoration must create stock movement history.  
**REC-011** Receive Stock must generate a reference number.  
**REC-012** Scanner/OCR must remain optional with manual-entry fallback.

### POS — Point of Sale

**POS-001** POS is available to Owner, Branch Manager, and Cashier.  
**POS-002** Non-owner POS users sell only from the assigned branch.  
**POS-003** Owner must choose a branch when processing a branch sale.  
**POS-004** Current POS must sell brand-new phones/tablets and accessories.  
**POS-005** Pre-loved devices are excluded until a dedicated flow is implemented.  
**POS-006** Serialized devices may appear only once per cart and always have quantity 1.  
**POS-007** Accessory quantity must not exceed current branch stock.  
**POS-008** Sale completion must re-read and lock current stock.  
**POS-009** Sale completion must re-read the effective server-side selling price.  
**POS-010** Supported payment methods are Cash, GCash, Maya, Card, and Bank Transfer.  
**POS-011** Cash payment must reject amount received below total.  
**POS-012** Change must be calculated server-side.  
**POS-013** Completing a sale must create one `sales` record and associated `sale_items`.  
**POS-014** Device sale must atomically set the exact unit to `sold`.  
**POS-015** Accessory sale must atomically decrement balance.  
**POS-016** Every sold line must create a stock movement.  
**POS-017** Sale item snapshots must preserve the item name/spec/identifier/price/cost at sale time.  
**POS-018** System must generate a unique sale number.  
**POS-019** Current total equals subtotal until discount/promo requirements are approved.

### MOV — Stock Movement

**MOV-001** Stock movement history must record stock in/out/sale/adjustment and future transfer/return/defective events.  
**MOV-002** Movement records must include branch, product, quantity, creator, and timestamp.  
**MOV-003** Serialized movements should reference the exact unit.  
**MOV-004** Sales/receipts/adjustments should carry a reference number.  
**MOV-005** Cost/selling snapshots may be stored where needed for audit and reporting.  
**MOV-006** Branch-scoped users may see only permitted branch movement history.

### USER — User Management

**USER-001** The system defines roles: Owner, Branch Manager, Inventory Staff, Cashier.  
**USER-002** Owner may see users across branches.  
**USER-003** Branch Manager may see only users in the assigned branch.  
**USER-004** Future user CRUD must enforce unique email, branch assignment rules, role rules, and active/inactive status.  
**USER-005** User deactivation must not delete historical actions.

### DASH — Dashboard

**DASH-001** Owner dashboard must support all-branch and selected-branch scope.  
**DASH-002** Branch users must see only assigned-branch metrics.  
**DASH-003** Dashboard must summarize available serialized stock and accessory balances.  
**DASH-004** Dashboard must show recent stock activity.  
**DASH-005** Owner dashboard must show branch inventory comparison.  
**DASH-006** Dashboard statistics must derive from transactional data, not manually stored counters.

---

## 7. Business Rules Summary

Detailed rules are in `RULES.md`. Key rules include:

- No duplicate IMEI/serial.
- No cross-branch sale/receipt for non-owner accounts.
- No sale of non-available serialized units.
- No accessory sale beyond current quantity.
- No client-controlled sale price.
- No direct deletion of transaction history.
- No product archive while available stock still exists.
- Owner-only current serialized inventory adjustment.
- Every inventory-changing transaction must create movement history.

---

## 8. Non-Functional Requirements

### Performance

- Normal pages should remain responsive with practical branch inventory volumes.
- Product/POS search must use indexed searchable fields where possible.
- Avoid loading tens of thousands of unit rows into a summary page at once.

### Reliability

- Sales and inventory adjustments must be atomic.
- Concurrency must prevent double-selling.
- Failed transactions must roll back completely.

### Security

- Prepared SQL statements
- CSRF protection
- Role/branch authorization
- Escaped output
- HTTPS and secure cookies in production
- Protected production credentials

### Maintainability

- Database migrations must be versioned.
- UI must follow `DESIGN.md`.
- Database definitions must follow `SCHEMA.md`.
- New behavior must follow `RULES.md`.

### Auditability

Critical operations must preserve:

- Who
- What
- Which branch
- Quantity/unit
- Reference
- When
- Reason/notes where required

---

## 9. Current Known Gaps

The current application code is ahead of the SQL files included in the package. Several runtime-required schema changes and sales tables are referenced by PHP but their migration files are not included.

Before treating Phase 2 as deployment-ready, the project needs:

1. Consolidated/versioned database migrations
2. Clean install test from empty database
3. Sales history/detail/receipt/reprint screen
4. User CRUD if user administration is required beyond viewing
5. Formal transfer workflow
6. Formal return/defective workflow
7. Pre-loved POS requirements
8. Production configuration hardening
9. Expanded audit/security logging

---

## 10. Suggested Phase Roadmap

### Phase 2A — Stabilize current POS

- Consolidate schema migrations
- Complete brand-new device/accessory POS
- Sales history and sale detail
- Receipt/reprint
- Branch pricing validation
- End-to-end tests

### Phase 2B — Inventory operations

- Branch-to-branch transfer
- Defective handling
- Supplier return
- Stock adjustment reporting
- Bulk receive/scanning improvements

### Phase 2C — Extended retail

- Pre-loved sales
- Discount/override authorization
- Customer details if required
- Returns/refunds/exchanges

### Phase 3 — Reporting and administration

- Sales reports
- Margin/profit reports for Owner
- Stock aging
- Fast/slow moving inventory
- Audit dashboard
- Backup/restore administration

---

## 11. Success Metrics

Recommended metrics after deployment:

- Inventory variance rate by branch
- Duplicate identifier attempts blocked
- Average POS transaction completion time
- Failed/rolled-back sale rate
- Number of stock adjustments by reason
- Stock-out incidents caused by inaccurate quantity
- Percentage of stock movements with valid reference/user
- Branch reconciliation time

---

## 12. Product Definition of Done

A feature is complete only when:

- Functional requirements and role scope are clear.
- Server-side validation is implemented.
- Database changes have migrations.
- Audit/history behavior is defined.
- Empty/error/success states are designed.
- Owner and branch scenarios are tested.
- Concurrency is tested for stock-changing features.
- The feature does not expose protected cost or cross-branch data.
- Documentation is updated when behavior or schema changes.
