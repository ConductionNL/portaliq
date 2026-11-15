# Design: site-at-the-root-of-a-portal-host

## D1. The root is served by a proxy rewrite, not by the app

An app's routes live under `/apps/<app>/`. The root of the instance belongs to Nextcloud (login, default app), and so do `/index.php`, `/remote.php`, `/ocs`, `/status.php` and the static prefixes. Answering `/` from portaliq would mean a front controller hook or a core patch, and it would answer for every host, including the workplace host.

A proxy in front of Nextcloud already knows the host. It rewrites the short address of a portal host to the path route, and leaves Nextcloud's own prefixes alone. The app keeps deciding which portal serves the request (D3), so the rewrite selects nothing by itself.

## D2. Root serving is declared per domain

The app cannot see whether a rewrite happened: a request for `/index.php/apps/portaliq/site/afval` looks the same whether it was typed or rewritten. Writing short links without a rule in front would produce links that land on Nextcloud's root. So an administrator declares `servedAtRoot` per domain, and the renderer writes short links only there, and only when the page itself was opened on a short address.

## D3. The host decides, and only a verified host

`PortalResolver::servingDomain()` answers the verified domain entry the request host matches, through the same match as `resolveByHost()` (`PortalDomainMatch`). The host is `IRequest::getServerHost()`, which honours `trusted_proxies` and `overwritehost`. A client header is never read. A request that names another portal by slug gets no domain, so one portal's host settings never shape another's pages.

## D4. Several domains, one portal, a landing page each

A portal already carries `domains[]`. `landingRoute` on a domain is the page its bare address opens. The route a request asks for is, in order: the path, `?route=`, the landing route of the host, `/`.

## D5. Sessions across hosts

The resident's portal session is a bearer in the tab's `sessionStorage`, keyed by origin. It is not a cookie. Two hosts are two origins, so a resident who signs in on `mijn.gemeente.example` is signed out on `www.gemeente.example/mijn`, and the other way round. The Nextcloud session cookie is host-only as well, and plays no part for residents except in the `nextcloud` sign-in mode.

The recommended set-up therefore keeps the resident on one origin: the proxy answers `mijn.gemeente.example` with a redirect to `https://www.gemeente.example/mijn`. `landingRoute` serves an operator who wants the second host to serve the site itself. Then the resident stays on that host, and the main host's link to `/mijn` asks them to sign in again.

## D6. Gateway rule set (Envoy Gateway, for the platform repository)

On the portal host, in this order:

1. Pass through unchanged, `PathPrefix`: `/index.php/`, `/apps/`, `/custom_apps/`, `/apps-extra/`, `/core/`, `/dist/`, `/js/`, `/css/`, `/themes/`, `/ocs/`, `/ocs-provider/`, `/ocm-provider/`, `/remote.php`, `/public.php`, `/status.php`, `/cron.php`, `/.well-known/`.
2. `Exact /`: `URLRewrite` `ReplaceFullPath` `/index.php/apps/portaliq/site/`.
3. `PathPrefix /`: `URLRewrite` `ReplacePrefixMatch` `/index.php/apps/portaliq/site/`.

On the second host, `RequestRedirect` to `https://<main host>/mijn` with status 302 (D5), or the same three rules when the second host serves the site itself.

The workplace host gets no rule. `overwritehost` must not be set globally: it would make every absolute address, sign-in callbacks included, point at one host.
