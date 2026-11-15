---
title: Publishing a portal on your own domain
sidebar_label: Custom domains
---

# Publishing a portal on your own domain

A Portaliq `portal` is normally reachable at the platform host. To publish it
at a customer's own name — `portal.example.com` — three things have to line up:
**DNS** points the name at Portaliq, **verification** proves the customer
controls that name, and **TLS** terminates somewhere with a valid certificate.

This page covers Cloudflare in detail because it is the common case, then the
generic recipe for any other DNS provider.

> **Order matters.** Add the domain in Portaliq first and publish the
> verification record *before* you point traffic. A domain that resolves to
> Portaliq but is not verified returns **404** — deliberately. See
> [Why verification exists](#why-verification-exists).

---

## 1. Add the domain in Portaliq

In the portal's settings, add `portal.example.com` to **Domains**. Portaliq
issues a verification token for that portal, shown next to the pending domain:

```
_portaliq-verify.portal.example.com   TXT   "portaliq-site-verification=<token>"
```

The domain stays **pending** until that record resolves.

---

## 2. Publish the DNS records

### Cloudflare

In the zone for `example.com`, add two records.

**a. The verification record**

| Type | Name | Content | Proxy |
| --- | --- | --- | --- |
| `TXT` | `_portaliq-verify.portal` | `portaliq-site-verification=<token>` | — (TXT is never proxied) |

**b. The traffic record**

| Type | Name | Content | Proxy status |
| --- | --- | --- | --- |
| `CNAME` | `portal` | `<platform-host>` | 🟠 Proxied *or* ⚪ DNS only |

Whether to proxy is a real choice, not a default:

| | 🟠 Proxied (orange cloud) | ⚪ DNS only (grey cloud) |
| --- | --- | --- |
| TLS at the edge | Cloudflare's certificate | — |
| TLS at the origin | still required — see below | Portaliq's certificate (ACME) |
| Visitor IP | in `CF-Connecting-IP` | direct |
| Caching | Cloudflare's cache is in front of Portaliq's | Portaliq's cache only |
| DDoS / WAF | yes | no |

**If you proxy, set SSL/TLS mode to Full (strict).** "Flexible" terminates TLS
at Cloudflare and speaks **plain HTTP to the origin** — the portal carries
session credentials, so that is not acceptable. Enable **Authenticated Origin
Pulls** so the origin only accepts connections coming from Cloudflare;
otherwise anyone who learns the platform host can bypass the edge entirely.

Verify the record resolves before continuing:

```bash
dig +short TXT _portaliq-verify.portal.example.com
dig +short portal.example.com
```

### Any other DNS provider

The same two records, in whatever the provider's UI calls them:

```
_portaliq-verify.portal   TXT     "portaliq-site-verification=<token>"
portal                    CNAME   <platform-host>
```

If a `CNAME` is not possible at that label — some providers refuse it at the
zone apex, and `example.com` itself is an apex — use `A`/`AAAA` records
pointing at the platform's addresses, or the provider's flattening feature
(Cloudflare calls it CNAME flattening; Route 53 calls it an ALIAS record).

Pointing an apex at a fixed IP means the platform can no longer change that
address without your involvement. Prefer a subdomain.

---

## 3. Verify

Back in Portaliq, trigger **Verify** on the pending domain. Portaliq resolves
the TXT record and compares the token. On success the domain goes **active**
and starts serving.

DNS propagation is the usual cause of a first failure. Check with `dig` against
an authoritative resolver rather than trusting a local cache:

```bash
dig +short TXT _portaliq-verify.portal.example.com @1.1.1.1
```

Leave the TXT record in place. Portaliq re-checks periodically, and a removed
record eventually unbinds the domain.

---

## 4. TLS

**Proxied through Cloudflare.** Cloudflare presents its own certificate to
visitors. The origin still needs a certificate — use a Cloudflare Origin CA
certificate, and turn on Authenticated Origin Pulls.

**Not proxied.** The origin needs a publicly-trusted certificate for
`portal.example.com`. Portaliq obtains one over ACME (Let's Encrypt) once the
domain is verified and resolving. The HTTP-01 challenge needs port 80 reachable
on the origin; if it is not, use DNS-01 instead.

---

## 5. Tell Nextcloud about the domain

Portaliq runs on Nextcloud, which refuses requests for hosts it does not know:

```bash
occ config:system:set trusted_domains 3 --value=portal.example.com
```

If generated absolute URLs must use the custom domain, also set
`overwrite.cli.url` and the `overwriteprotocol`/`overwritehost` pair for the
site's requests.

Skipping this produces a very confusing failure: the page loads, but links,
redirects and asset URLs point back at the platform host. It looks like a
theming or asset problem and is actually a routing one.

---

## 6. Serve the site at the root of the domain (optional)

**The bare address works without this step.** On a verified domain of a published portal, Portaliq answers `https://portal.example.com/` (and `/index.php`) with a redirect to the site's long address, on the domain's `landingRoute` when it has one, else the home page. It does so for guests and signed-in users, before Nextcloud would show its login page or default app. `/login` and every other path are left alone, so an employee can still sign in on the portal host. A host that is not a verified domain of a published portal, the workplace host among them, keeps Nextcloud's own root.

Without the rest of this step the site lives at `https://portal.example.com/index.php/apps/portaliq/site`. To serve its pages at short addresses such as `https://portal.example.com/afval`, a proxy in front of Nextcloud rewrites the short addresses of that host to the site's path route. Only Nextcloud's root can be answered from the app; every other short address needs the proxy.

If you prefer the proxy to answer the bare address as well (so Nextcloud is not reached for it), nginx can redirect it per host:

```nginx
# In the server block of the portal host only.
location = / {
    return 302 /index.php/apps/portaliq/site;   # or .../site/mijn on the host of the signed-in area
}
```

That rule names the portal's page in proxy configuration, outside the portal's settings, and must be kept in step with `landingRoute` by hand.

1. Add the rewrite for the portal host only. Leave Nextcloud's own prefixes alone and rewrite everything else to `/index.php/apps/portaliq/site/` followed by the path.
2. Set `servedAtRoot` to true on that domain of the portal. The site then writes its links and its canonical address as short addresses. Leave it false without the rewrite: short links would land on Nextcloud's own root.
3. Optional: set `landingRoute` on a domain to open another page at its bare address, for example `/mijn` on a host for the signed-in area.

Both settings count only on a verified domain. An unknown or unverified host still gets 404.

**Envoy Gateway (`HTTPRoute`)**, on the portal host:

```yaml
rules:
  - matches:   # Nextcloud's own paths, unchanged
      - path: { type: PathPrefix, value: /index.php/ }
      - path: { type: PathPrefix, value: /apps/ }
      - path: { type: PathPrefix, value: /custom_apps/ }
      - path: { type: PathPrefix, value: /core/ }
      - path: { type: PathPrefix, value: /dist/ }
      - path: { type: PathPrefix, value: /js/ }
      - path: { type: PathPrefix, value: /css/ }
      - path: { type: PathPrefix, value: /themes/ }
      - path: { type: PathPrefix, value: /ocs/ }
      - path: { type: PathPrefix, value: /remote.php }
      - path: { type: PathPrefix, value: /public.php }
      - path: { type: PathPrefix, value: /status.php }
      - path: { type: PathPrefix, value: /.well-known/ }
    backendRefs: [{ name: nextcloud, port: 80 }]
  - matches:
      - path: { type: Exact, value: / }
    filters:
      - type: URLRewrite
        urlRewrite: { path: { type: ReplaceFullPath, replaceFullPath: /index.php/apps/portaliq/site/ } }
    backendRefs: [{ name: nextcloud, port: 80 }]
  - matches:
      - path: { type: PathPrefix, value: / }
    filters:
      - type: URLRewrite
        urlRewrite: { path: { type: ReplacePrefixMatch, replacePrefixMatch: /index.php/apps/portaliq/site/ } }
    backendRefs: [{ name: nextcloud, port: 80 }]
```

Add the path your instance serves extra apps from (`/apps-extra/` and the like) to the first rule.

**nginx**, in the `server` block of the portal host:

```nginx
location ~ ^/(index\.php|apps|custom_apps|core|dist|js|css|themes|ocs|remote\.php|public\.php|status\.php|\.well-known)(/|$) {
    # Nextcloud's own paths: the usual Nextcloud configuration
}
location / {
    rewrite ^/(.*)$ /index.php/apps/portaliq/site/$1 last;
}
```

**Signed in on two hosts.** A resident's session is kept per browser tab and per host. A resident who signs in on `mijn.example.com` is signed out on `example.com/mijn`. Keep residents on one host: let the proxy answer the second host with a redirect to `https://example.com/mijn`, or let the second host serve the whole site with a `landingRoute`, so the resident stays on it.

**Do not set `overwritehost` for the whole instance** when one instance answers on several hosts. It makes every absolute address, sign-in callbacks included, point at that one host. Set `trusted_domains` for each host and `trusted_proxies` for the proxy instead.

---

## Caching, when a CDN is in front

Portaliq caches public content keyed by **portal + route + locale + audience**
and marks its responses accordingly:

| Response | Header |
| --- | --- |
| Anonymous, published content | `public, max-age=…` |
| Anything per-visitor | `private, no-store` |

A CDN in front must honour that. **Do not add a Cloudflare Cache Rule that
caches by URL alone**, and do not enable "Cache Everything" on the portal
hostname without an exclusion for authenticated responses. A shared cache that
ignores the audience will serve one signed-in visitor's page to everybody —
at the edge, where our own logs will not show it.

If you want edge caching, scope the rule to anonymous content: match on the
absence of the session cookie, and respect origin cache headers rather than
overriding them.

---

## Framing

Each portal declares which origins may embed it. Portaliq derives
`Content-Security-Policy: frame-ancestors` from that configuration per portal.
If a customer needs the portal in an iframe on their own site, add that origin
to the portal's configuration — do not disable the header.

---

## Why verification exists

Without it, "point DNS at us" would be enough for one tenant to serve content
under another tenant's name. Anyone can create a DNS record for a hostname they
do not own the trademark to; the TXT record is the proof that whoever asked
Portaliq to serve `portal.example.com` also controls `example.com`.

This is why an unverified domain returns 404 rather than falling back to a
default portal. **An unknown `Host` resolves to no portal at all** — a fallback
would mean showing some tenant's content to a visitor who asked for another's.

---

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| 404 on the custom domain | Domain not verified, or verification lapsed. Check the TXT record. |
| 404 on the custom domain, TXT present | DNS not propagated to Portaliq's resolver yet; check with `dig … @1.1.1.1`. |
| Certificate warning | Proxied with SSL/TLS mode set to Flexible, or ACME has not completed. |
| Page loads, links point at the platform host | `trusted_domains` / `overwrite*` not set. |
| Links on the root of the domain lead to the Nextcloud login | `servedAtRoot` is true but the proxy has no rewrite for that host. |
| The bare address of the domain shows the Nextcloud login | The domain is not verified, the portal is not published, or Nextcloud resolves another host for the request (check `trusted_proxies` and that `overwritehost` is not set). |
| Signed-in content shown to anonymous visitors | A CDN cache rule caching by URL alone. Remove it. |
| Portal will not load in a customer's iframe | The origin is not in the portal's `frame-ancestors` configuration. |
| Redirect loop | Proxied with SSL/TLS mode Flexible while the origin redirects HTTP → HTTPS. Use Full (strict). |

---

## Related

- ADR-086 — Portaliq is the fleet's headless CMS (§11 custom domains)
- ADR-082 — public endpoint throttling
- ADR-005 — fail-closed security
