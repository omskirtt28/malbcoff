---
tags: [log]
---
# Changelog (update after every patch)
- 2026-10-16 — Vault created. Plan written, nothing patched yet. See [[Owner Updates Plan]].
- 2026-10-16 — **Patch 1 done**: Stock Monitoring owner view fixed. Owner + any branch = branch-user view scoped to that branch; owner + All Branches = matrix (Grand Total = sum of branch columns); export follows same rule; topbar switcher hidden on this page. Files: `core/StockMonitoringReport.php`, `pages/stock-monitoring.php`, `actions/export_stock_monitoring.php`, `partials/topbar.php`. Bug [[BUG Stock Monitoring Owner Branch View]] → fixed.
