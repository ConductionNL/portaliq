# Continuation plan — portal design programme

Written 2026-08-17, extended 2026-08-18. Everything here is measured, not
remembered: where the work is, what is done, what is left, and the traps that
cost time.

## Status

**Every task in all four changes is closed.** 95 done, 0 open, 7 partial — and
each partial names exactly what remains and why it is not in this repo.

| Change | done | open | partial |
| --- | --- | --- | --- |
| portal-contribution-endpoint-actions | 12 | 0 | 0 |
| portal-traffic-analytics | 32 | 0 | 3 |
| portal-page-composition | 29 | 0 | 1 |
| nldesign-theme-integration | 22 | 0 | 3 |

### What is genuinely still open, and where it lives

- **[openregister#2548](https://github.com/ConductionNL/openregister/pull/2548)** —
  an unowned-write opt-in on `saveObject()`. Until it merges, an "anonymous"
  submission is stamped with the Nextcloud user id of any visitor who happens to
  hold a session. **Then one line here**: `createAnonymousObject()` passes
  `_unowned: true`. Deliberately not written yet — an unknown named argument is
  a fatal, so adding it early would take every anonymous submission down.
- **[nldesign#357](https://github.com/ConductionNL/nldesign/pull/357)** — docs
  recording portals as a second consumer, including the dark-variant defect.
- **The dark token sets need regenerating.** Linking them made a portal
  measurably worse (0 of 8 surfaces changed, text below AA 0 → 19 of 38, worst
  1.03:1) because an alias resolves where it is DECLARED: the dark file
  redefines base colours on `body` while the light set declares the aliases
  above them on `:root`. No change in this app fixes it.
- **The traffic Traffic page** does not exist in this repo under any spelling.
  `GET /api/traffic/summary` serves everything it would need.
- **`openregister` should record** that its read log is deliberately not the
  traffic source — written here in `docs/traffic-privacy.md`, not yet mirrored
  there.

### Three findings worth more than the tasks they came from

1. **An "anonymous" write is stamped with the session user's id.** Measured:
   `__system__` from `curl`, `admin` from a browser with a cookie, on the same
   form. Fixed upstream.
2. **A check that never ran looks exactly like one that passed.** The theme
   contrast verdict reported **46 of 46 sets passing with 43 of them
   unmeasured**. It now carries a `measured` count and reports "not checked".
3. **The gates found an IDOR in my own code.** `TrafficController::summary`
   shipped `#[NoAdminRequired]` while taking a portal slug, so any authenticated
   user could read any tenant's traffic. `PageRegionsController::update` had the
   same shape and was not flagged.

## Where the work lives

| What | Where | Branch | State |
| --- | --- | --- | --- |
| Portal renderer, traffic collector, specs | `apps-extra/portaliq` | `feat/portal-nextcloud-signin` | pushed |
| Conduction + Frankendesk token sets, public bridge | worktree `~/gate19-worktrees/nlds-conduction` | `feat/conduction-2026-tokens` | pushed |
| Docusaurus plugin | `apps-extra/docusaurus-plugin-portaliq` | `main` | **local only — never pushed** |

The nldesign work is in a **worktree**, not the main `apps-extra/nldesign`
checkout — that one sits on `chore/coverage-guard-changed-files`, another
workstream. Edit the worktree, or copy into it and commit there.

## Done, with the numbers

- **Chrome parity with docs.conduction.nl**: header 11/11, hero 24/24 (plus
  16/16 for the heading icon and honeycomb), cards 24/24, footer 41/44.
- **Site bundle 370.1 KiB against a 400 KiB budget** (e2e S18, measured on
  transferred bytes). It was 393.6 with 6.4 KiB of headroom until the duplicated
  design-system CSS came out.
- **`npm run check:surfaces`** — 12 page/width combinations across both demo
  portals: contrast against what is actually painted, exactly one `h1`, no
  horizontal overflow. It **self-tests first**: injects a low-contrast colour, a
  second `h1` and a 3000px element and refuses to judge anything if it fails to
  detect them.
- **Traffic collector**: `POST /api/traffic`, `portalTrafficEvent` schema,
  `portal.traffic` config, 10 tests. No IP is stored and the signature enforces
  it — the service takes a region and has no parameter that could carry an
  address.
- **Docusaurus plugin**: 17 tests, verified against the live La Franken portal.

## Next, in the order I would do it

### 1. ~~Publish the Docusaurus plugin~~ — DONE

Live at `ConductionNL/docusaurus-plugin-portaliq`, public, default branch `main`,
EUPL-1.2 text shipped alongside the licence the package declared, 19 tests
passing. Scanned before publishing: the only token-shaped string in the repo is
a synthetic fixture used to assert redaction.

### 2. ~~Task 6.2~~ — CLOSED, and the diagnosis below was wrong

Kept because being wrong about it is the lesson. `anonymous: true` **is**
honoured — on a `type: create` action, through
`POST /portal/api/collections/{register}/{schema}`, which admits a caller with
no bearer. Proven before touching anything: an unauthenticated `curl` created an
object on the first try. The renderer was posting every action to the
endpoint-FORWARD route, which refuses a create with or without a session. The
"either/or" framing below assumed the endpoint was at fault; it was not.

### 2b. What replaced it — the original text

The manifest declares the "Melding indienen" action `anonymous: true`
("geen account nodig"), but `ContributionController::action()` answers **401
without a session for every action**. A citizen portal is currently offering an
anonymous route that cannot work. Decide one way: the endpoint honours the flag,
or the manifest stops offering it. The renderer already reports the endpoint's
real behaviour rather than the manifest's claim, so nothing is lying on screen —
but the contract disagrees with itself.

### 3. Traffic — CLOSED except three parts, each named

- **The scheduled job.** `expiredIds()` decides what is past retention and
  `aggregate()` produces the figures; **nothing calls either on a timer.**
  Retention is therefore not enforced yet. This is the largest remaining gap
  and it is a background job, not new logic.
- **The Traffic page.** Does not exist in this repo. `GET /api/traffic/summary`
  serves everything it would need — sessions, engaged sessions, visitors, page
  views, ranked entrances, exits and transitions — and returns `{measured:
  false}` with **no counter keys** for a portal that measures nothing, so a
  renderer cannot plot zeroes it was never given.
- **Task 7.2's other half.** The reason OpenRegister's read log is not the
  traffic source is written in `docs/traffic-privacy.md`; it is not yet mirrored
  into the `openregister` repo, which is where the next person will look.

Three CORS defects were fixed getting a statically built portal to report at
all, and only a real browser on a real second origin found them: the script's
obvious URL 401s for anonymous callers, neither public endpoint sent any CORS
header and the collector's preflight answered 405, and **`sendBeacon` always
sends credentials-include so the browser rejects a wildcard CORS response** —
the client now keeps the beacon same-origin and uses a keepalive fetch with
credentials omitted elsewhere, which keeps the server strict rather than
loosening it to an origin echo.

### 3b. The original traffic plan, for reference

- **Client library (5.1–5.5).** The collector has nothing sending to it. One
  first-party script, shipped from **both** the Docusaurus plugin and the
  built-in renderer *from the same source* so the two cannot drift. Must honour
  Do Not Track and the portal's consent posture **before** touching browser
  storage, and send *and store* nothing when measurement is off.
- **Sessionisation and aggregation (4.1–4.5).** Order journeys by `sequence`,
  never by receipt time; test with out-of-order delivery. Aggregation must be
  idempotent — running it twice must not double a count.
- **Config to the client (2.2), rate limiting (3.4), anonymity test (3.6).**
  3.6 matters: a guard nobody has watched refuse is untested.

Two things already decided and worth not re-deriving: measurement is
**client-reported** (nothing server-side can see a Docusaurus-built portal, and
OpenRegister's read log is an AVG verwerkingsregister, not analytics); and
`regionFor()` returns `''` rather than a plausible country, because an
unmeasured value sitting beside measured ones is worse than an empty field.

### 4. Page composition — 25 tasks, the largest

Regions and a wireframe editor. Nothing in it is blocked; it is simply big.
Worth splitting into "regions as data" and "the editor" and shipping the first
alone.

### 5. Theme integration — 13 tasks, and dark mode is now unblocked

**2.6 is done.** All eight painted surfaces resolve
`--nldesign-site-{surface,surface-raised,surface-sunken}` →
`--utrecht-document-background-color` → the literal, so a generated dark variant
reaches the page. Verified by moving them: setting the utrecht token alone
repaints all eight, where before it repainted none. Light mode is provably
unchanged — every fallback is the colour measured beforehand, and re-enumerating
the eleven painted surfaces afterwards returns identical colours and areas.

**2.2 and 2.4 are the natural next step** and are no longer blocked. Expect to
need text colour too: it was deliberately left out of 2.6 because
`--utrecht-document-color` is `#1b1b23` while body text computes to
`rgb(0, 0, 0)`, so including it would have been a visible change disguised as a
no-op refactor. `tests/site-surfaces.spec.mjs` is what will say whether the dark
variant actually works — it composites alpha and self-tests before judging.

## Traps that cost time here

- **`occ upgrade` is a no-op when the app version is unchanged.** A migration
  added to OpenRegister today never ran, and the symptom was a generic "problem
  saving your schema" far from the cause. Postgres named it in one line:
  `docker logs conduction-postgres --since 30s | grep ERROR`. **Ask the database,
  not the app.**
- **`git init -b main` is not supported by this git**, and stderr suppressed it.
  The follow-on `git add -A` then committed **5,698 files** to another
  workstream's branch. Never suppress stderr on a command you are about to
  trust; stage named paths, never `-A`.
- **A module leaving the bundle is not bytes leaving the bundle.** Excluding a
  1.65 MB CSS module changed the output by **zero**. The saving was in nine
  small component packages. Measure the artefact, not the module list.
- **Vendored NLDS CSS out-specifies the obvious selector.** Its rules are
  `.ac-footer section:first-of-type <element>` — (0,2,2) to (0,3,3). Tokens
  resolve, the declaration sits in the CSSOM, and the computed value is still
  the vendored one. `CSS.getMatchedStylesForNode` names the winner.
- **`--navigation-bar-height` cannot be bridged from `:root`** — `.ac-header`
  declares it on itself, and a custom property resolves from the nearest
  ancestor that declares it.
- **`getComputedStyle().fontFamily` is the declared string.** Both portals
  reported the right font while rendering DejaVu Sans.
  `CSS.getPlatformFontsForNode` reports what actually drew the glyphs.
- **A contrast check that ignores alpha is decorative.**
  `rgba(255,255,255,0.22)` on navy scored 17.85:1 until compositing was added.
- **The reference fails AA in three places** (footer links 4.28, orange CTA
  3.01). Deviations from it are deliberate and commented where they occur.
- **The reference shuffles its skyline per load.** Ours is fixed on purpose, so
  a screenshot diff means something.
- **A schema declared in `portaliq_register.json` is not a schema that exists.**
  `portal.traffic` had been added and marked done; the live table had no
  `traffic` column, so no portal could ever have been configured.
  `POST /api/settings/load` applies it. **Ask the database.**
- **New routes need the route cache cleared**, and `occ maintenance:repair`
  takes ~9 minutes and leaves the instance in maintenance mode while it runs.
  `docker exec nextcloud apachectl graceful` clears APCu in seconds and is
  enough. If a repair does get started, **wait for it** — killing the
  `docker exec` does not kill the process inside the container, and the 503 that
  follows is maintenance mode still held by a live repair.
- **A 43-byte response is a 401.** It cost time twice in one session: once
  comparing an md5 of a login page against a bundle, once probing a script URL.
  Check the status before believing the body.
- **The browser caches the bundle even when the server has the new bytes.** A
  fetch from devtools gets fresh bytes while the page runs the cached script, so
  "the server is serving my fix" and "the page is running my fix" are different
  claims. The traffic client is cached an hour by design; bust it explicitly
  when testing.
- **Prove an absence test can fail, and fail for its OWN reason.** The traffic
  client's "stores nothing when disabled" tests passed against a deliberately
  removed guard, because the consent gate was blocking the send instead. Stand
  the other gates down in each case.
- **`<background-jobs>` in `info.xml` is read only on install and upgrade.** The
  declaration was in place and `oc_jobs` held zero portaliq rows. Register at
  boot behind a config flag if the job must exist on an instance that is already
  running the app.
- **phpcs demands named arguments into framework code, and a guessed name is a
  FATAL, not a lint failure.** `setInterval(interval:)` threw "Unknown named
  parameter" at construction. Read the signature; do not infer it from the
  setter's name.
- **A task's premise can be stale — check it before implementing.** Three were:
  6.2 asked which of two things to change when the answer was neither, 6.1 of
  the traffic change asks to replace counters on a page that does not exist, and
  the skipped-heading defect turned out to be data, not code.

## How to check the work

```sh
cd apps-extra/portaliq
npm run check:surfaces     # 12 combos, self-testing; needs the instance at :8080
npm run check:specs        # + site-contribution and traffic-client
npm run build:traffic      # the standalone client, budgeted at 8 KiB (it is 3.9)
npm run build:site         # fails the 400 KiB budget as an ERROR, not a warning
docker exec -u www-data -w /var/www/html/custom_apps/portaliq nextcloud \
  php vendor/bin/phpunit --no-coverage   # 568 tests

cd apps-extra/docusaurus-plugin-portaliq && npm test   # 17 tests
```

Demo portals: `http://localhost:8080/index.php/apps/portaliq/site?portal=conduction-klant`
and `…?portal=lafranken`.
