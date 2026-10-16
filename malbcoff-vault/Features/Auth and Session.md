---
tags: [feature]
---
# Auth and Session
`login.php` → `Auth::attempt` (+ throttle in `Security`). Session: idle 30 min, absolute 12 h, UA-hash check, regenerate every 15 min. CSRF token per session (`Csrf`). `must_change_password` gate in `bootstrap.php`. System Admin impersonation (`Auth::beginImpersonation`).
Weak internet note: 30-min idle expiry + CSRF means a queued/offline action can come back "session expired" → Patch 6 needs a keep-alive + re-auth path.
