---
tags: [feature]
---
# Auth and Session
`login.php` → `Auth::attempt` (+ throttle in `Security`). Session: idle 30 min, absolute 12 h, UA-hash check, regenerate every 15 min. CSRF token per session (`Csrf`). `must_change_password` gate in `bootstrap.php`. System Admin impersonation (`Auth::beginImpersonation`).
Weak internet note: 30-min idle expiry + CSRF means a queued/offline action can come back "session expired" → Patch 6 needs a keep-alive + re-auth path.

**Tablet login bug (2026-10-18):** the UA-hash check ended sessions when the service worker re-sent page requests with a different User-Agent (tablets use "desktop site" mode). Fixed in the worker; the check stays strict. The login page now explains why you are back on it (wrong password / security token expired / cookies / session ended) and has an off-by-default debug flag `login_debug`. See [[BUG Tablet Login Stays on Login Page]].
