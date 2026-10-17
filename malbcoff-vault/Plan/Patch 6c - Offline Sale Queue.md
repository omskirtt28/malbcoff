---
tags: [plan, patch, network, offline, pos]
---
# Patch 6c-2 — Offline sale queue (old plan note)
**Status: DONE 2026-10-17 — see [[Patch 6c-2 - Offline Sales Outbox]]** (that note has the final behaviour, server rules, migration `P3_005` and the acceptance list).

This file is kept only because older notes link to it. The original idea (reuse the [[Patch 6c-1 - Offline Outbox for Stock Actions]] outbox with a `sale` type, send each sale with its 6a key, let the server re-validate, send conflicts to the owner's Needs Action list, keep the typed prices from [[Patch 8 - Price at Checkout]]) is exactly what was built. Differences from this old plan: the device time is accepted for the sale date within 7 days (not 48 h) and is stored next to the server time; the duplicate guard is a UNIQUE `sales.client_id` in addition to the idempotency key; a small "seen earlier" search cache lets the cashier keep selling while offline; there is no "Pending sync" receipt page (there is still no receipt page) — the temporary receipt number `OFFLINE-<branch>-<date>-<n>` is shown instead.
