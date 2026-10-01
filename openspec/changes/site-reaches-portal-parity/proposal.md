---
kind: code
depends_on: [portal-theme-blocks-and-contributed-pages]
supersedes: [portal-shared-runtime]
---

# Proposal: site-reaches-portal-parity

## Why

Portaliq serves two front ends to the same residents. The React portal at
`/apps/portaliq/portal` (`src/portal/`) is the signed-in surface. The Vue site
at `/apps/portaliq/site` (`src/site/`) is the public surface and the one
ADR-084 and hydra ADR-109 keep. Every feature built for residents since August
landed in the React portal, so the one we keep cannot do what the one we retire
does.

`portal-shared-runtime` proposed the retirement but no longer describes the
code. It counts 1,208 lines in seven files. Measured on `development` at
9a160863, `src/portal/` holds 59 files and 10,878 lines: 32 components, 17
libraries, the embed frame, the service worker, 283 translated strings and a
513-line stylesheet. It also plans a rebuild on `bootstrapCnApp` and
`CnJourney`, neither of which the site uses. This change replaces it.

## What changes

- Every capability of `src/portal/` gets a Vue replacement under `src/site/`.
  `design.md` lists each one with its React source, what the site does today,
  the Vue target, whether its library moves unchanged or is rewritten, and the
  node test that covers it now.
- Framework-free libraries move to `src/shared/` unchanged and keep their
  node tests. Both bundles import them from there until the React portal is
  deleted.
- The work is cut into seven slices, one task section each: the shell;
  collections; forms and actions; inbox, messages, news and tasks; account
  and cases; PWA and embed; retirement.
- Retirement: `/portal` redirects to `/site` with its query string, the
  server's own redirects and mail links point at `/site`, and `src/portal/`,
  `webpack.portal.js`, `templates/portal.php` and the React dependencies go.
  The `/portal/api/*` endpoints stay; the site already calls them.
- "Parity reached" is a measurable checklist (requirement REQ-SRP-051).

## Affected projects

- [ ] `portaliq`: the site renderer, a new `src/shared/`, the embed entry,
      `PortalPageController`, `SessionController`, `BrokerSessionController`,
      `PortalDeepLinkBuilder`, `PortalManifestController`, the e2e suite and
      the docs.
- [ ] `learniq`: `tests/e2e/po-parent-flows.spec.ts:393` opens the React
      portal. It moves to `/site` (site-parity P3).
- [ ] `hydra`, `dossiq`: specs and designs that cite `src/portal/` files are
      updated once the files are gone. No code depends on them.

## Out of scope

- The `/portal/api/*` surface. It stays as it is.
- New resident features. A feature an open change plans for the React portal
  is retargeted to `src/site/` (see the dated note in each of those
  proposals), not built twice.
- Theme token sets. thematiq owns them; this change only keeps the site
  linking the serving portal's set.

## Risks

- **Two token stores.** The React portal keeps its bearer in localStorage
  `portaliq_token`, the site in sessionStorage `portaliq.session.token`. A
  resident with only the old key is signed out once after the switch.
- **The site budget.** `webpack.site.js` fails the build at 412 KiB and the
  entry sits 182 bytes under it. Every ported screen must load on demand.
- **The redirect hides callers.** Mails, OIDC callbacks and the PWA
  `start_url` all land on `/portal` today. They must point at `/site`
  directly, so the redirect is only for old links.
- **An auth-gated portal shows an error on `/site` today.** Measured on the
  integration instance: `wilgenboom` answers 401 `authentication_required` on
  the content API and the site renders "Er ging iets mis". The React portal
  shows its sign-in screen. Slice a fixes this first.
