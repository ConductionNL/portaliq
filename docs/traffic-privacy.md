# Portal traffic: what is measured, and what is not

For whoever operates a portal, and for whoever has to answer a question about
it under the AVG. Everything here is what the code does, not what it intends.

## Measurement is off unless a portal turns it on

A portal with no `traffic` block, or one with `enabled: false`, collects
nothing. Absent configuration means **no** — measurement on a public government
portal is something an operator decides, not something a missing field decides
for them.

An enabled portal that names no events also collects nothing. "Enabled but
unconfigured" is the state a half-finished admin screen leaves behind, and it
must not widen to everything.

## What is stored

Per event: the portal, an opaque client id, a session id, the client's own
sequence number, the event name, the client's timestamp, the server's receipt
time, the page **path and query** (never the origin), the referrer, the page
title, a coarse region, and a bounded `params` map.

`params` is capped at 20 keys of 256 characters, and non-scalar values are
dropped. An unbounded map is how personal data reaches an analytics store by
accident — a client that puts a form's contents there loses them here rather
than having them kept because nothing said otherwise.

## What is never stored

**No IP address.** The request address is resolved to a coarse region and
dropped inside `TrafficController::collect()`, before anything else sees it.
`PortalTrafficService` takes a region string and has no parameter that could
carry an address — the signature is the guarantee, not a comment asking people
to be careful. The test searches the *whole* stored record for the address, so
passing one as the region would still fail.

**No cookie.** The collector answers 204 with no body and sets nothing.

**No region, currently.** `regionGranularity` is honoured to the extent that
`none` stores nothing; no geo resolver is wired in, so `regionFor()` returns an
empty string rather than a plausible-looking country. An unmeasured value
sitting beside measured ones is worse than an empty field.

## What the visitor controls

**Do Not Track and Global Privacy Control are honoured before anything else.**
Either signal makes the client silent — no send, and nothing written to browser
storage. GPC is included deliberately: it is the successor signal and carries
actual legal weight in some jurisdictions, and honouring the deprecated one
while ignoring the live one has the posture backwards.

**Where a portal requires consent**, nothing is written to the browser until it
is given. Events the portal lists as permitted before consent still travel, but
they carry an id held only in memory for that page — so a pre-consent visit
leaves no trace. Withdrawing consent clears the client id, not just the flag.

## Two independent switches for a statically built portal

A portal built by `docusaurus-plugin-portaliq` is static HTML on another host.
It loads the portal's own client rather than a bundled copy, so the two
renderers cannot reach different conclusions about what a browser may store.

The plugin's `traffic: false` emits no script tag at all. Left on, the script
still measures nothing unless the portal has enabled it. Both must be on,
because the site's operator and the portal's operator can be different people
and either may decline.

The client fetches the portal's settings **at runtime**, never at build time.
Baking them in is the shape that keeps measuring after an operator switches
measurement off; a privacy decision that needs a site rebuild to take effect is
a privacy decision that does not work.

## Retention

`retentionDays` bounds how long raw events are kept; aggregates outlive them. A
row whose age cannot be read is **kept** — deleting what you cannot date is how
a retention job becomes a data-loss incident. A retention of zero deletes
nothing: "unset" must not read as "expire everything immediately".

## Why this is client-reported, and not something else

Three alternatives were checked before any of this was built, and each is
recorded so nobody re-derives them:

- **`portalSession` cannot answer it.** Its fields are `subjectRef`, `jti`,
  `issuedAt`, `expiresAt`, `revoked`, `trustLevel`, `audience`, `organisation`.
  Nothing about pages, and it exists only for authenticated visitors — the bulk
  of a public portal's traffic has none.
- **OpenRegister's read log is not analytics, and switching it on would not
  make it so.** `ProcessingLogService::logRead()` works and is opt-in per
  schema (`logReads`, enabled on 0 of 13 schemas here). It is an AVG
  *verwerkingsregister*: object-level, actor-attributed, with no visit,
  referrer, entrance, exit or ordering. Pointing analytics at it would also
  make an accountability record carry traffic, which is the one thing an
  accountability record must not do.
- **A statically built portal cannot be measured server-side at all.** The
  plugin fetches content at build time and emits HTML hosted elsewhere;
  portaliq is not in the request path when a visitor reads it.

## Known gap

An "anonymous" submission — and a traffic event — is stamped with the
Nextcloud user id of a visitor who happens to hold a Nextcloud session on the
same instance. The cause is in OpenRegister: `SaveObject::applyOwnerAttribution()`
is the sole authoritative setter and stamps the session user unconditionally,
so a caller-set owner is honoured only when there is no session. Measured, not
inferred: the same submission stored `__system__` from `curl` and `admin` from
a browser holding an admin cookie. It needs a narrow OpenRegister change (an
explicit unowned-write opt-in on `saveObject()`), and until then the guarantee
holds for a visitor with no Nextcloud account — the common case — and fails for
staff browsing their own portal.
