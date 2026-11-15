---
kind: code
---

# Proposal: portal-traffic-analytics

## Summary

First-party traffic measurement for portals: what visitors look at, how they
move between pages, and where they arrive from and leave. Modelled on the GA4
event vocabulary so the numbers mean what people already expect them to mean,
but **what** is collected is configured per portal, and the collector is our
own — a government portal should not be posting its visitors to a third party
to find out that its Woo page is popular.

The client half ships with `docusaurus-plugin-portaliq`, because a
Docusaurus-rendered portal is exactly the case that cannot be measured any
other way.

## What we already know — measured, not assumed

The starting question was whether journeys can be reconstructed from data we
already hold: "if page reads have sessions then we can abstract how a session
moved". They cannot, and the reasons are worth stating precisely, because each
one is a thing that looks like it would work.

| source | what it holds | why it cannot answer "how did this visitor move" |
| --- | --- | --- |
| `portalSession` objects | `subjectRef`, `jti`, `issuedAt`, `expiresAt`, `revoked`, `trustLevel`, `audience`, `organisation` | **No page-level data of any kind**, and it only exists for AUTHENTICATED portal users. A public government portal's traffic is overwhelmingly anonymous, and anonymous visitors have no `portalSession` at all. |
| OpenRegister read logging | `ProcessingLogService::logRead()` — a real, working read log | It is **opt-in per schema** via `logReads`, and **no portaliq schema opts in**: verified against `lib/Settings/portaliq_register.json`, 13 schemas, zero with `logReads`. Today it records nothing for portal content. |
| …if we switched it on | object id, actor, channel, processing activity | It is an **AVG/GDPR verwerkingsregister**, not an analytics log. It answers "who accessed this personal data, under which processing activity" — object-level, with no concept of a visit, a referrer, an entrance, an exit, or an ordering between two reads by the same visitor. Reusing it for analytics would both fail to answer the question and pollute an accountability record that exists for a legal purpose. |
| `/api/metrics` | Prometheus gauges: app info, health, counts | Instance-level totals. No visitor dimension, by design. |
| Nextcloud session cookie | a per-browser id on the NC-hosted renderer | Incidental, not ours, absent on any portal not served by Nextcloud, and not something to build a measurement contract on. |
| a Docusaurus-rendered portal | **nothing** | The plugin fetches content at BUILD time and emits static HTML hosted elsewhere. When a visitor reads that page, portaliq is not in the request path at all. No server-side signal exists to log, now or ever. |

**The conclusion is structural, not a gap to be filled by turning something
on.** Server-side reads describe what the CMS was asked for, not what a person
saw: one page view can cause several object reads, a cached page causes none,
and a statically built page never touches us. Journeys have to be reported by
the client that renders them.

### The one thing that would look like it works

Switching on `logReads` for `page` would produce a plausible-looking table
immediately — rows with timestamps, ordered, attributable to an actor. It would
be wrong in a way that survives review: identical for two visitors served from
cache, empty for the Docusaurus sites, and counting renderer prefetches as
human attention. Anyone comparing it against a real analytics tool would
conclude the tool was wrong. We should not ship a number that is easier to
believe than to check.

## What we measure, and who decides

The GA4 model is the reference because it is the one every communications
officer already reads: **events** grouped into **sessions** belonging to a
**client id**, with page/location/referrer context and engagement time. The
default event set is small and unsurprising — `page_view`, `session_start`,
`scroll`, `outbound_click`, `file_download`, `search`, `form_submit`.

What differs is that the set is **per portal**. A municipality running a Woo
portal may want file downloads and search terms; one running a supplier portal
may want neither. So the portal record carries an explicit list of enabled
events and dimensions, the collector **refuses** anything not on it, and the
client is told what to send rather than deciding for itself.

That refusal is the mechanism, not a formality: a collector that accepts
whatever arrives has no configuration, it has a default.

## Privacy posture, stated up front

- **No third party.** The collector is a portaliq endpoint on the portal's own
  origin. Nothing is sent to Google, and no vendor script is loaded.
- **No cross-site identity.** The client id is first-party, per portal, and
  carries no meaning outside it.
- **IP is never stored.** It is used to derive coarse geography and to
  rate-limit, then discarded in the same request.
- **Consent-aware from the start**, not retrofitted: a portal declares whether
  measurement runs before consent, and which events survive a refusal.
- **Retention is finite and configured**, with raw events aggregated and then
  deleted — a traffic log that keeps everything forever is a personal-data
  liability that nobody chose.

## Affected Projects

- [ ] `portaliq` — the collector endpoint, the `portalTrafficEvent` schema, the
      per-portal configuration, the aggregation job, and the Traffic page that
      currently shows three counters.
- [ ] `docusaurus-plugin-portaliq` — ships the client, so a statically built
      portal reports the same events as a server-rendered one.
- [ ] `openregister` — no change. Its read log stays what it is: an AVG
      processing record.

## Out of scope

- Behavioural profiling, A/B testing, per-person journey inspection.
- Any cross-portal or cross-domain identity.
- Real-time dashboards. Aggregation is batch; "who is on the site right now"
  is a different system with different privacy consequences.
