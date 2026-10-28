---
type: plan
status: active
created: 2026-10-26
---
# Agent Pipeline

Bawat prompt na build/fix/patch: `planner` -> `backend-engineer` + `ux-designer` (10 taon) -> `patch-tester` (test at kritika). Hanggang 2 fix rounds. Ang main session ang nag-o-orchestrate. Laktaw sa tanong, docs-only, one-line fix.

- Auto-continue pagkatapos ng plan, MALIBAN kung schema, roles/auth, payments, stock ledger o deletes ang gagalawin (magtanong muna).
- Ang update package ay gagawin LANG kapag may trigger phrase ni John ([[Deploy Rule]]).
- Fix sweep: ayusin ang tinuro + i-sweep ang same pattern (UI at backend). Report: Fixed / Found and also fixed / Found but left alone / Not verified.
- Agents: `.claude/agents/` ng malbcoff. Dati silang kopya ng ORDESK na tumuturo sa ORDESK-Vault; inayos na (2026-10-26).
