# Proposal: site-at-the-root-of-a-portal-host

## Why

A portal on its own verified domain still answers only at `/index.php/apps/portaliq/site?route=/afval`. A municipality wants `https://www.gemeente.example/` and `https://www.gemeente.example/afval`. Nothing serves that today:

- `appinfo/routes.php` maps the app's own `/` to `dashboard#page`, and the root of the Nextcloud instance is its login or default app. An app cannot answer at the root of the instance.
- The site renderer reads its page only from `?route=` (`App.vue` `routeFromLocation`) and writes every link and history entry as `?route=` on the long address.
- The canonical address in the served head is always the long address.

A second need came with it: a portal may have a second host for the signed-in area (`mijn.gemeente.example`) whose bare address opens that area, not the home page.

## What changes

- A path route beside the site route: `/apps/portaliq/site/{path}` serves the same shell, with the page taken from the path. This is the address a proxy rewrites a short address to. When the request resolves to no portal it answers 404.
- Two settings on a portal domain: `servedAtRoot` (an administrator declares that a proxy rewrites this host's short addresses) and `landingRoute` (the page the bare address of this host opens). Both are read only from a verified domain the request host matches, and only when the request does not name another portal.
- The shell hands the renderer `addressing` (`root`, `siteRoute`, `landingRoute`). The renderer reads the page from the path and writes short links on a declaring host, and keeps long addresses everywhere else. A page opened on the long address stays on long addresses.
- The served canonical address is the short one on a declaring host.
- The operator doc gives the proxy rule for Envoy Gateway and for nginx.

## What does not change

- An unknown host, an unverified domain and an unknown slug still resolve to no portal. There is still no default portal.
- `/apps/portaliq/site?route=` keeps working on every host, so nothing published so far breaks.
- The workplace host (`cloud.` in the example) has no domain entry and keeps plain Nextcloud.

## Not in this change

- The proxy rule itself. It lives in the platform's gateway configuration.
- Fields for the two settings in the portal settings screen. They are on the schema and can be set through the register. The Domeinen field-list can show them in a follow-up.
- Return addresses after sign-in. `SiteReturnAddress` accepts only the long site route, so a sign-in started on a short address returns to the long address of the same host, signed in. Accepting short paths needs the addressing in three controllers and is a follow-up.

## Affected projects

- portaliq: `appinfo/routes.php`, `lib/Controller/PortalPageController.php`, `lib/Service/PortalResolver.php`, `lib/Service/PortalDomainMatch.php`, `lib/Service/SiteAddressing.php`, `lib/Service/SiteShell.php`, `lib/Settings/portaliq_register.json`, `src/site/App.vue`, `src/site/lib/siteAddress.js`, `docs/operations/custom-domains.md`, tests.
