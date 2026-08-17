# Tasks: portal-traffic-analytics

## 0. Establish the baseline before building anything

- [x] 0.1 Determine what `portalSession` can answer. **Nothing about pages**: its fields are `subjectRef`, `jti`, `issuedAt`, `expiresAt`, `revoked`, `trustLevel`, `audience`, `organisation`, and it exists only for authenticated portal users. Anonymous visitors — the bulk of a public portal — have none.
- [x] 0.2 Determine whether OpenRegister already records page reads. **It does not.** `ProcessingLogService::logRead()` exists and works, but is opt-in per schema via `logReads`, and `lib/Settings/portaliq_register.json` enables it on **0 of 13** schemas.
- [x] 0.3 Determine whether switching it on would answer the question. **No.** It is an AVG verwerkingsregister: object-level, actor-attributed, with no visit, referrer, entrance, exit or ordering. It would also make an accountability record carry analytics traffic.
- [x] 0.4 Determine whether a Docusaurus-rendered portal can be measured server-side. **No.** The plugin fetches content at BUILD time and emits static HTML hosted elsewhere; portaliq is not in the request path when a visitor reads it.
- [ ] 0.5 Write the finding into the openspec archive as the reason the design is client-reported, so the next person does not re-derive it by switching on `logReads` and believing the table.

## 1. The event contract

- [x] 1.1 Define the event envelope: `clientId`, `sessionId`, `sequence`, `name`, `timestamp` (client clock), `pageLocation`, `pageReferrer`, `pageTitle`, plus a bounded `params` map.
- [x] 1.2 Define the shipped event vocabulary — `page_view`, `session_start`, `scroll`, `outbound_click`, `file_download`, `search`, `form_submit` — with the GA4 name for each, so a number here and a number in GA4 mean the same thing.
- [x] 1.3 Define the `portalTrafficEvent` schema in the register.
- [x] 1.4 Test: an event whose `sequence` repeats within a session is rejected — a client that resets its counter must not silently corrupt a journey.

## 2. Per-portal configuration

- [x] 2.1 Extend the `portal` schema: `traffic: { enabled, events[], dimensions[], sessionTimeoutMinutes, retentionDays, consent: { required, preConsentEvents[] }, regionGranularity }`.
- [ ] 2.2 Serve the resolved configuration to the client over the public content contract, so the client sends only what the portal asked for.
- [x] 2.3 Test: a portal enabling only `page_view` gets exactly that — the collector refuses `search`, and the refusal is counted, not silent.
- [x] 2.4 Test: a configuration field is not merely stored — assert the collector's BEHAVIOUR changes with it. A declared-but-unread config field is this codebase's most repeated defect.

## 3. The collector

- [x] 3.1 `POST /api/traffic` — anonymous, batched, `sendBeacon`-compatible, responding 204 with no body and no cookie.
- [x] 3.2 Resolve the serving portal the same way the renderer does (host, then explicit slug), so an event cannot be attributed to a portal the caller names.
- [~] 3.3 Derive coarse region from the request IP and DISCARD the IP in the same request. Test that no stored field, log line or aggregate contains it. **Half done, and the half that is done is the guarantee.** The address is resolved and dropped inside `TrafficController::collect()`; `PortalTrafficService` takes a region string and has no parameter that could carry an address, so the signature enforces it rather than a comment. Asserted by searching the WHOLE stored record for the address, so passing one as the region would still fail. NO GEO RESOLVER IS WIRED IN: `regionFor()` returns '' rather than a plausible-looking country, because an unmeasured value sitting beside measured ones is worse than an empty field.
- [ ] 3.4 Rate-limit per client id and per source, refusing the excess with a counted reason.
- [x] 3.5 Refuse an oversized or malformed batch WHOLE — never store a partial batch.
- [ ] 3.6 Test: the endpoint is genuinely anonymous. A guard nobody has watched refuse is untested — assert a real anonymous request succeeds AND that a disabled portal's collector refuses.

## 4. Sessionisation and aggregation

- [ ] 4.1 Close sessions after the configured inactivity window; a later event starts a new session.
- [ ] 4.2 Reconstruct journeys by `sequence`, not by receipt time. Test with events delivered out of order — the case that only shows up on slow connections.
- [ ] 4.3 Aggregate: views per page, entrances, exits, transitions between pages, sessions, engaged sessions, average engagement time.
- [ ] 4.4 Delete raw events past the retention window; keep the aggregates.
- [ ] 4.5 Test: the aggregation job is idempotent — running it twice does not double a count.

## 5. The client, shipped with the Docusaurus plugin

- [ ] 5.1 A small first-party script: generates and stores the client id, maintains the session and sequence, sends batched beacons, and sends only the configured events.
- [ ] 5.2 Ship it from `docusaurus-plugin-portaliq` so a statically built portal reports the same events as a server-rendered one, posting cross-origin to its portal's collector.
- [ ] 5.3 Wire the same client into the built-in site renderer, from the same source, so the two cannot drift.
- [ ] 5.4 Honour Do Not Track and the portal's consent posture before writing anything to browser storage.
- [ ] 5.5 Test: with measurement disabled the script sends NOTHING and stores NOTHING — assert both, because a script that stores an id and sends nothing still sets a cookie.

## 6. The Traffic page

- [ ] 6.1 Replace the three placeholder counters with the aggregates.
- [ ] 6.2 Show the journey: top entrances, top exits, most-travelled transitions.
- [ ] 6.3 Say "not measured" for a portal with measurement disabled, and never render an empty chart for it — a zero and an unmeasured are different facts.
- [ ] 6.4 Test: a portal with no data and a portal with measurement off render DIFFERENTLY.

## 7. Documentation

- [ ] 7.1 Document the privacy posture in the portal admin: what is collected, what is never collected, how long it is kept.
- [ ] 7.2 Record in `openregister` that its read log is deliberately NOT the traffic source, so the two are not conflated later.
