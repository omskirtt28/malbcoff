---
tags: [feature]
---
# Stock In (Receive Stock)
`pages/stock-in.php` (2953 lines ⚠, mostly inline JS) + `actions/stock_in.php`. Wizard: model → variant → qty & scan IMEI/serial → confirm. Writes `inventory_units` / `inventory_balances` + `stock_movements(stock_in)`. Route allowed: branch_manager, inventory only (owner NOT in `$pageRoles`, though code has owner branch select). Needs network-retry safety: Patch 6 ([[Owner Updates Plan]]).
**Patch 6c-1:** if the confirm tap fails because of the network, the receipt is saved on the device (outbox, same key; payload without `_csrf`/`receive_nonce`) and sent later — `stock_in.php` skips only the one-time review nonce for `X-Outbox-Send: 1`; a refusal (e.g. serial already received) becomes Needs action for the owner. Stock numbers do not change until it is sent. See [[Patch 6c-1 - Offline Outbox for Stock Actions]].
**Patch 6a:** the confirm button uses `SafeSubmit.run`; `stock_in.php` answers JSON for `Accept: application/json`, honours the key (replay returns the first result even though the single-use review nonce was already consumed). Inventory/Products quick-receive sends a key too.
