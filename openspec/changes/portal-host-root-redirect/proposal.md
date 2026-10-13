# Proposal: portal-host-root-redirect

## Why

A portal's verified domain points at the same Nextcloud instance as the workplace. Without a path rewrite in front of Nextcloud (the decision for nginx with HTTP-01 certificates), a visitor of `https://www.gemeente.example/` gets Nextcloud's own root: the login page for a guest, the default app for a signed-in user. The site is only reached on its long address. The same holds for a second host such as `mijn.gemeente.example`, which should bring a resident to the sign-in of the portal.

`site-at-the-root-of-a-portal-host` (#1542) added the long path route `/apps/portaliq/site/{path}`, the per-domain `servedAtRoot` and `landingRoute`, and strict host resolution. It left the root itself to a proxy rule. This change answers the bare address from the app, so a deployment without that rule still leads a visitor to the site.

## What changes

- On a request for the bare address of the instance (`/`, `/index.php`, `/index.php/`), as a page (GET or HEAD, through `index.php`, not a JSON or XML client), on a host that is a verified domain of a published portal, the app answers 302 to the long address of the site: the domain's `landingRoute` when it has a valid one, else the site's home.
- The decision runs in the app's `boot()`, which Nextcloud runs for every app before it matches the route and answers the bare address itself.
- Every other request, and the bare address on every other host, is left to Nextcloud unchanged.

## What does not change

- `/login` is never redirected, so an employee can still sign in on a portal host.
- No new domain setting. `landingRoute` keeps its meaning (the page the bare address opens) and its validation. `servedAtRoot` does not change the target: the redirect always names the long address.
- An unknown host, an unverified domain and a forged host header resolve to no portal, as before, and get plain Nextcloud.

## Affected projects

- portaliq: `lib/AppInfo/Application.php`, `lib/Service/SiteAddressing.php`, `lib/Service/HostRootRedirect.php`, `lib/Service/HostRootRedirectSend.php`, `docs/operations/custom-domains.md`, tests.

## Depends on

- `site-at-the-root-of-a-portal-host` (#1542): `PortalResolver::servingDomain()`, `PortalDomainMatch`, `SiteAddressing`, the route `portalPage#sitePath` and the two domain fields.
