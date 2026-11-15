# Design: portal-host-root-redirect

## D1. Where Nextcloud answers the bare address

Traced in Nextcloud 32.0.13 (`lib/base.php`) and confirmed in 35 (`lib/OC.php`, same steps, other line numbers):

1. `index.php` calls `OC::handleRequest()`.
2. Apps are loaded: everything for a signed-in user (base.php:1051), the filesystem and logging apps plus the login handling for a guest (1054-1061), then all apps for both (1089). Loading an app boots it (`AppManager::loadApp()` calls `Coordinator::bootApp()`, AppManager.php:481), so every app's `boot()` has run at this point.
3. `Router::match($request->getRawPathInfo())` (1091). The raw path info is `''` for `/`, `/index.php` and `/index.php/` (Request.php:749-750). No route matches it, so the router throws and the request falls through.
4. JSON or XML clients get 404 (1110-1115).
5. For an empty path, core sets its own `Location`: the default page for a signed-in user (`linkToDefaultPageUrl()`, 1132), the login page for a guest (`core.login.showLoginForm`, 1135).

## D2. Why boot, and not another hook

- **A route at `/`.** App routes are prefixed with `/apps/<app>`. A route's `root` key is honoured only for a fixed list of core apps (RouteParser.php:18-28 and 212). The legacy `$this->create()` in `routes.php` does add an unprefixed route, but only as a deprecated `action` route (Router.php:322-324, a warning on every hit) that would answer `/` on every host and have to rebuild core's answer for the workplace.
- **The login page event.** `BeforeLoginTemplateRenderedEvent` only exposes the template response; it cannot turn it into a redirect. And `/login` reached by core's redirect from `/` looks exactly like `/login` typed by an employee, so redirecting there would lock employees out of the portal host.
- **The default page.** `linkToDefaultPageUrl()` reads `core/defaultpage` or the user's navigation default. Neither knows the host, and neither applies to a guest.
- **A middleware.** It wraps controllers. The bare address reaches no controller.
- **`boot()`.** It runs for every app, for guests and signed-in users, before step 3. It can decide from the request and end the request with a redirect. That is the hook.

## D3. Ending the request

Core sets its `Location` after boot, and a later `header()` replaces an earlier one. So the redirect only holds when the request ends where it is sent. `HostRootRedirectSend` sends 302 with a `Location` and exits, the way core ends its own early answers. It is a class of its own so tests can watch it.

## D4. What counts as the bare address

The same request core treats as the entry point: `getRawPathInfo() === ''` through `index.php` (the script name rules out `cron.php`, `status.php`, `remote.php` and the console, which also give an empty path info), GET or HEAD, and not a JSON or XML `Accept`. These checks are cheap and come first; the portals are read only for a request that passes them. A path info that cannot be read is not the bare address.

## D5. The target

`SiteAddressing::hostRoot()` reads the verified domain through `PortalResolver::servingDomain()` (the host Nextcloud resolved, honouring `trusted_proxies`, never a client header), and answers a path on the same host built from the route table: `portalPage#sitePath` with the cleaned `landingRoute`, or `portalPage#site` for the home page. The host is not written into it, so a relative `Location` cannot lead to another origin.

`servedAtRoot` does not change the target. Behind the proxy rule it declares, `/` never reaches Nextcloud. When `/` does reach Nextcloud, the rule is missing, and a short address would lead back to `/`: a loop.

A signed-in user on a portal host is redirected too. The portal host is the public site; the workplace has a host of its own, and every other path (`/apps/files`, `/login`) still works on the portal host.

## D6. Failure

Any error while reading portals answers null: plain Nextcloud. The resolver already fails closed (an empty portal list on an OpenRegister error).

## D7. Cost

`boot()` runs on every request. The request checks are string comparisons. The portal read (one OpenRegister `findAll`, memoised for the request) happens only for the bare address, which then gets a redirect anyway, also on the workplace host.
