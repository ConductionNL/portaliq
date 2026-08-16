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
| Signed-in content shown to anonymous visitors | A CDN cache rule caching by URL alone. Remove it. |
| Portal will not load in a customer's iframe | The origin is not in the portal's `frame-ancestors` configuration. |
| Redirect loop | Proxied with SSL/TLS mode Flexible while the origin redirects HTTP → HTTPS. Use Full (strict). |

---

## Related

- ADR-086 — Portaliq is the fleet's headless CMS (§11 custom domains)
- ADR-082 — public endpoint throttling
- ADR-005 — fail-closed security
