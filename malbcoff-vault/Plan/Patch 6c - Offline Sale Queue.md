---
tags: [plan, patch, network, optional]
---
# Patch 6c — (Optional) Offline sale queue

Only do if Branch needs to keep selling with NO net. Risky: stock/price are validated server-side at sync time.

## Design
- IndexedDB queue `pending_sales` {client_ref, cart snapshot, payment, created_local_at}. POS keeps a cached copy of last-fetched catalog for branch (from `pos_search.php`) in IndexedDB for search while offline.
- When offline: sale saved as **PENDING** (receipt marked "Pending sync"), unit marked reserved locally so same IMEI can't be added twice.
- On reconnect (`online` event + 30 s timer): POST each in order via `netFetch` + `client_ref` (6a). Server re-validates stock/price; conflicts (unit already sold) go to a "Needs attention" list for the manager.
- `pages/pos.php`: "Pending (n)" chip + sync/resolve panel. `complete_sale.php`: accept `offline_at` timestamp for `sales.created_at` (validated, ≤ 48 h old) and mark `sales.offline_synced=1` (migration).
- Security: queue holds no passwords; require valid session at sync (else prompt login then continue).

## Acceptance
- [ ] Airplane mode: complete 3 sales; reconnect → 3 sales appear once, correct branch, stock decremented.
- [ ] Conflict case (unit sold elsewhere meanwhile) is flagged, not silently dropped.
- [ ] Clear browser data warning shown if pending > 0.
