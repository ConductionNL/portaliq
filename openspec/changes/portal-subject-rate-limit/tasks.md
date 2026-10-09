# Tasks: portal subject rate limit

- [x] 1. `PortalRateLimit` (per subject with a session, per IP without); `collection()` asks it; the attribute is the outer bound.
- [x] 2. The site page shows a load error for a failed table or figure read.
- [x] 3. `WidgetGrid::forwardSearch` passes only a string.
- [x] 4. Tests: `PortalRateLimitTest`, `tests/portal-subject-rate-limit.spec.mjs`.
- [ ] 5. Live check on the proof instance: five page loads in a minute keep their blocks; Enter in the search field.
