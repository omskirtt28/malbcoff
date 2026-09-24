# Malbcoff Trading POS & Inventory System — UI/UX Design System

> **APPROVED PRODUCTION MASTER DESIGN — September 2026**  
> `design-reference/approved_responsive_ui_reference.png` is the official visual reference for Desktop/Laptop, Tablet, and Phone. It is no longer a concept. Every current and future module must use the same design language, responsive shell, component styling, navigation behavior, forms, tables, modals, buttons, status states, and spacing system.

## Non-Negotiable UI/UX Rule

**One system → one design language → one responsive experience.**

- Desktop keeps the fixed left sidebar and compact top bar.
- Tablet removes the permanent sidebar and uses the compact header + bottom navigation.
- Phone uses a true single-column touch interface; wide tables become labelled record cards instead of forcing horizontal desktop layouts.
- POS responsive order is **Find Item → Cart → Payment → Complete Sale → Recent Sales**.
- Touch controls on tablet/phone target at least ~44px.
- Primary blue, white surfaces, light blue-gray background, dark navy text, subtle borders/shadows, rounded cards, and outline icons are the official visual language.
- Existing POS, sales, inventory, barcode/IMEI/serial, payment, receiving, stock movement, permissions, and database logic must not be changed solely for visual redesign.
- Shared partials and centralized tokens/components must be reused instead of styling every page independently.

**Master reference file:** `design-reference/approved_responsive_ui_reference.png`

---

**Design source:** `design-reference/approved_responsive_ui_reference.png` + centralized tokens in `assets/css/app.css`  
**Style direction:** clean, business-focused, responsive-first for desktop/laptop, tablet, and phone

---

## 1. Design Goals

The interface must make daily retail operations easy to understand without requiring technical knowledge. The UI should feel consistent across Owner, Branch Manager, Inventory Staff, and Cashier experiences.

Primary goals:

- Fast scanning of inventory and sales information
- Clear branch context at all times
- Simple forms with obvious required fields
- Strong visual distinction between normal, warning, error, and success states
- Minimal clutter and no unnecessary decorative elements
- Responsive enough for laptop, tablet, and smaller screens
- Core UI and icons usable without internet/CDN access

---

## 2. Visual Foundation

### Core design tokens

These values reflect the current CSS baseline and should remain the default unless a deliberate redesign is approved.

| Token | Value | Use |
|---|---|---|
| Background | `#f5f7fb` | App page background |
| Surface | `#ffffff` | Cards, sidebar, top bar |
| Surface soft | `#f9fbff` | Secondary surfaces |
| Text | `#101828` | Main text |
| Muted | `#667085` | Supporting text |
| Muted 2 | `#98a2b3` | Low-emphasis text |
| Border | `#e4e9f2` | Dividers, cards, inputs |
| Primary blue | `#1769e8` | Active nav, primary actions |
| Primary hover | `#0f5ed7` | Button hover |
| Blue soft | `#eef5ff` | Selected/active background |
| Green | `#1eaa68` | Success / available |
| Green soft | `#eaf8f0` | Success tint |
| Amber | `#e58a18` | Warning / low stock |
| Amber soft | `#fff6e5` | Warning tint |
| Red | `#dc4c4c` | Error / destructive / stock out |
| Red soft | `#fff0f0` | Error tint |
| Purple | `#7357d9` | Secondary category/accent |
| Purple soft | `#f2efff` | Purple tint |

### Typography

Use the existing system stack:

```css
-apple-system, BlinkMacSystemFont, "Segoe UI", Inter, Roboto, Helvetica, Arial, sans-serif
```

Rules:

- Page title: approximately 24–28px depending on viewport, bold, compact line height.
- Card title: 16px, bold.
- Body: 14px.
- Supporting labels: 11–13px.
- Eyebrows: uppercase, ~10px, high weight, letter-spaced.
- Avoid using more than 3 major text sizes in one card.

### Radius and elevation

- Main card radius: `14px`
- Compact card/input group: `9–12px`
- Pills/chips: full/999px radius
- Use subtle shadows only; avoid heavy floating panels.

---

## 3. Application Shell

### Sidebar

- Fixed left sidebar on desktop.
- Desktop width: `236px`.
- Brand block at the top.
- Navigation links use icon + English label.
- Active item uses soft blue background and primary blue icon/text.
- Branch/user context and Sign Out belong at the bottom.
- Only show navigation items the current role can actually access.

Current primary navigation:

1. Dashboard
2. Sales Records — Owner, Branch Manager, Cashier
3. Inventory
4. POS — branch-side selling roles only
5. Products — branch-side operations
6. Receive Stock — branch-side operations
7. Stock Movement — Owner, Branch Manager, Inventory Staff
8. Users — Owner and Branch Manager

### Top bar

- Desktop height: `72px`; compact responsive height: `66px`.
- Owner sees a branch scope selector.
- Non-owner sees a non-editable assigned branch chip.
- User name and role remain visible on the right.
- Branch context must never be hidden during inventory/POS operations.

### Main content

- Desktop content max width: approximately `1480px`.
- Desktop page padding: approximately `26–28px`, reduced progressively on tablet/phone.
- Use a page heading with eyebrow, title, description, and at most one strong primary action.

---

## 4. Core Components

### Buttons

**Primary:** blue background, white label. Use for Save, Complete Sale, Receive Stock, or the single main action on a screen.

**Secondary:** white background + neutral border. Use for cancel, back, filter reset, less important actions.

**Outline blue:** white background + blue border/text. Use for View Units and safe secondary actions.

**Destructive:** red visual treatment and confirmation required before irreversible action.

Rules:

- Button text should be an action phrase: `Complete Sale`, `Receive Stock`, `Save Variant`.
- Do not place multiple visually equal primary buttons next to each other unless the workflow truly has equal choices.
- Disabled buttons must be visibly disabled and non-clickable.

### Cards

Cards are the basic grouping element. Each card should contain one clear subject.

Recommended structure:

```text
Card header
  Title
  Supporting description
  Optional action
Card body
```

### Status pills

Use status colors consistently:

- Green: available, active, completed, successful
- Amber: low stock, attention needed, warning
- Red: error, inactive where dangerous, failed, destructive/out
- Blue: informational/current scope
- Neutral gray: archived/inactive/non-operational

Never rely on color alone; always include status text.

### Tables

- Headers remain concise.
- Numbers align consistently.
- Actions appear at the right edge.
- Use empty states instead of blank tables.
- For unit-level inventory, show identifiers in a readable format and allow View Units details rather than overloading the summary table.
- Desktop tables stay compact and stable. On phones, `data-table` rows become labelled cards when necessary; avoid forcing wide desktop tables or horizontal scrolling.

### Forms

- Label always visible above or with the control.
- Required fields use `*`.
- Provide a placeholder such as `Select branch` instead of silently defaulting to the wrong choice.
- Error messages should explain what must be corrected.
- Preserve entered values when practical after validation errors.
- Price inputs clearly indicate PHP / ₱ context.

---

## 5. Dashboard Design

### Owner Dashboard

Must prioritize cross-branch visibility:

- Total Inventory
- Phones Available
- Tablets Available
- Accessories Stock
- Pre-Loved Units
- Stock In Today
- Recent Stock Activity
- Branch inventory summary

Owner branch filtering should update data scope while preserving the owner-level layout.

### Branch Dashboard

Must focus only on the assigned branch:

- Branch inventory totals
- Available device/accessory counts
- Recent stock activity
- Stock by item type

Do not show cross-branch financial or stock information to branch-scoped users.

---

## 6. Product Master Design

The catalog flow should be easy to understand as:

```text
Brand → Model → Variant
```

### Variant presentation

Display a compact specification string from relevant fields:

- RAM
- Storage
- Connectivity for tablets
- Color

Examples:

```text
8GB • 256GB • BLACK
256GB • Wi-Fi + Cellular • SPACE GRAY
```

Apple variants may omit RAM where the current business rule does not require it.

### Archive/delete behavior

Archive must visually differ from Delete.

- Archive = reversible catalog status change.
- Delete = cleanup action allowed only under strict rules.
- When history is retained, communicate that historical stock/sales remain protected.

---

## 7. Receive Stock UX

Receive Stock is a high-risk operational screen and should behave like a guided workflow.

Recommended order:

1. Branch/location
2. Product/model/variant
3. Variant creation only if the required option does not exist
4. Cost/selling price fields according to role
5. Quantity or identifier entry
6. Device condition where applicable
7. Notes
8. Review and submit

### Serialized device entry

- Show whether the expected identifier is Serial Number, IMEI, or both.
- Duplicate detection should appear before final submit when possible, but final validation must still occur server-side.
- Scanning/OCR buttons should be adjacent to the input they populate.
- A scanner failure must never block manual entry.
- Restoration of an adjusted-out unit must clearly identify that the unit existed before and is being restored, not created as a duplicate.

### Accessories

Use quantity input and barcode/product information. Avoid rendering one row per physical accessory.

---

## 8. POS Design

POS must optimize for speed while keeping payment and stock checks explicit.

### Layout concept

```text
Product search / scan       Cart
Search results              Item lines
                            Totals
                            Payment method
                            Amount received / reference
                            Complete Sale
```

### Product search

- Support model/spec/identifier/barcode search.
- Clearly mark unavailable items.
- Serialized devices add exactly one physical unit to cart.
- Accessories can change quantity without duplicate cart lines.

### Cart

Each line should show:

- Item name
- Specs/category
- IMEI/serial/barcode where useful
- Quantity
- Unit selling price
- Line total
- Remove action

Do not expose protected acquisition cost to Cashier.

### Payment

Supported current methods:

- Cash
- GCash
- Maya
- Card
- Bank Transfer

For Cash:

- Amount received is required.
- Change updates clearly.
- Complete Sale remains blocked if received amount is below total.

For non-cash methods:

- Payment reference may be collected where applicable.
- The exact server-side total must be displayed before confirmation.

### Sale success

Display:

- Sale number
- Branch
- Payment method
- Total
- Change, if any

Future receipt/reprint must reuse the same sale record, not generate a new sale.

---

## 9. Search, Scanner and OCR UX

Scanner/OCR features are optional accelerators.

Required UX states:

1. Ready
2. Requesting camera permission
3. Scanning/reading
4. Value detected
5. Duplicate/conflict found
6. Unable to detect — manual entry available

Do not silently save a detected OCR value. Populate the field, allow review, and apply normal validation.

---

## 10. Responsive Behavior

Desktop remains the primary operational target, but the interface must degrade safely.

### Large screens

- Fixed sidebar
- Full tables
- Two-column dashboards/POS where applicable

### Tablet / medium screens

- Sidebar may collapse behind a toggle.
- Grids reduce column count.
- Forms stack into fewer columns.
- Tables may horizontally scroll.

### Small screens

- One-column card flow.
- Primary action remains reachable.
- Avoid tiny tap targets; interactive controls should be roughly 40px+ high where possible.
- Critical identifiers and totals must not be truncated beyond recognition.

---

## 11. Accessibility and Usability

- All form fields need programmatic labels.
- Buttons must be keyboard accessible.
- Focus states must stay visible.
- Icons must not be the only meaning for important actions.
- Text/background contrast should meet normal business UI accessibility standards.
- Confirmation dialogs should name the affected item/action.
- Error messages should not disappear before the user can read them.

---

## 12. UI Copy Rules

- Use English labels consistently.
- Prefer simple retail terminology: `Receive Stock`, `Available`, `Sold`, `Branch`, `Selling Price`.
- Avoid developer terminology in user-facing messages such as table names or SQL migration names except in an administrator/debug context.
- Empty-state copy should explain the next useful action.
- Avoid technical stack traces or raw database errors in the UI.

---

## 13. Design Guardrails for Future Patches

Do:

- Reuse existing cards, buttons, pills, spacing, typography, and icons.
- Keep new UI aligned to the approved owner and branch reference direction.
- Preserve current blue-centered visual identity.
- Test Owner and branch-scoped views separately.
- Test empty, loading, success, warning, and error states.

Do not:

- Introduce a second design system for a new module.
- Add CDN-only UI libraries for basic components.
- Use excessive gradients, shadows, animations, or decorative charts.
- Add fields to a form without explaining their role in the workflow.
- Show controls that the current user cannot use.
- Put destructive actions next to routine actions without visual separation.

---

## 14. Design Definition of Done

A UI patch is complete when:

- It follows existing visual tokens and component patterns.
- Role/branch context is obvious.
- Required and optional fields are clear.
- Empty/error/success states exist.
- Desktop and responsive layouts are checked.
- Scanner/OCR has manual fallback.
- No protected data is revealed to unauthorized roles.
- The patch does not visually regress existing screens.
