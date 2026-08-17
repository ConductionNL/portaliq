# Continuation plan — portal design programme

Written 2026-08-17 as a handoff. Everything here is measured, not remembered:
where the work is, what is done, what is next, and the traps that cost time.

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

### 1. Publish the Docusaurus plugin — needs a human

`ConductionNL/docusaurus-plugin-portaliq` does not exist. Creating a public repo
under the org is outward-facing, so it was left. The code is committed locally at
`df37d74` and passes 17 tests.

### 2. Task 6.2 — La Franken advertises a route that does not exist

The manifest declares the "Melding indienen" action `anonymous: true`
("geen account nodig"), but `ContributionController::action()` answers **401
without a session for every action**. A citizen portal is currently offering an
anonymous route that cannot work. Decide one way: the endpoint honours the flag,
or the manifest stops offering it. The renderer already reports the endpoint's
real behaviour rather than the manifest's claim, so nothing is lying on screen —
but the contract disagrees with itself.

### 3. Traffic — 20 tasks left, in three shippable groups

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

### 5. Theme integration — 14 tasks

The valuable remainder is **2.6**: give the site a token-driven surface layer
(bands, cards, the page itself). It is the real prerequisite for dark mode —
2.2 and 2.4 are blocked on it and say so.

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

## How to check the work

```sh
cd apps-extra/portaliq
npm run check:surfaces     # 12 combos, self-testing; needs the instance at :8080
npm run check:specs        # json, manifest, register, registry, site-auth, site-grid
npm run build:site         # fails the 400 KiB budget as an ERROR, not a warning
docker exec -u www-data -w /var/www/html/custom_apps/portaliq nextcloud \
  php vendor/bin/phpunit --no-coverage   # 568 tests

cd apps-extra/docusaurus-plugin-portaliq && npm test   # 17 tests
```

Demo portals: `http://localhost:8080/index.php/apps/portaliq/site?portal=conduction-klant`
and `…?portal=lafranken`.
