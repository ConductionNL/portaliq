---
kind: code
depends_on: [portal-shared-runtime, portal-headless-content-api]
---

# Proposal: embedded-intake-form

Competitor gap register, row Q1.16 "Can the intake form be embedded on the
municipality's own website, and does a submission arrive as a case"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated no, owner portaliq, slug `embedded-intake-form`, size
M. Opened by the last sweep of the OpenSpec phase.

## Summary

A municipality puts a form on gemeente.nl, a visitor fills it in without
an account, and the submission arrives as a case. The form is a portaliq
portal page served for another origin: same contribution contract, same
validation, same throttle, same audit. The case app changes nothing.

## Why

Most people never reach the portal. They reach gemeente.nl, and the
journey ends there unless the form is on the page they are already
looking at.

The best competitor, verbatim from the register's `best` column: "Jira
Software DC 11.3 (documented): the issue collector embeds a form in your
own site and visitors need no account; Zammad 7 serves one embeddable form
per install, driven in batch 3
(`_round4/compare/proposed-rows-batch10.md`)".

The register's note: "`grep -rn -i \"iframe|embed|widget.js|postMessage\"
lib/ src/` returns UUID and EXIF matches only; the one public intake
endpoint is `lib/Controller/DSOIntakeController.php` (`#[PublicPage]`),
which receives DSO submissions from the Omgevingsloket, not a form on the
municipality's site. The portal move to portaliq is where this would
land". Its `covered` column: "none (portal-public-search and the journey
serve portaliq's own pages, not another origin)".

The register's `why`: "the public form is portaliq's (1.1, ADR-046); an
embed on gemeente.nl is that form served for another origin". ADR-086
says the same thing from the other side: portaliq is a headless CMS
first, and a capability that exists only inside our own renderer is a
defect in the API, not a feature of the portal. An embed is the first
consumer that proves it.

## Scope

- A portal page of type form gains an embed. The admin copies a snippet
  from the page: one script tag with the form id, which mounts the form in
  an iframe served from the portal's origin.
- **Allowed origins per form.** The portal records them. A frame request
  from an origin not on the list renders nothing, and the
  `frame-ancestors` policy is built from that list, never a wildcard.
- **A submission is a contribution create.** The same anonymous path the
  portal's own form uses, the same schema validation, the same throttle
  (ADR-082), the same audit entry, the same subject scoping rules. The
  case arrives through `portal-contribution-contract` and dossiq's intake
  binding is unchanged.
- **No account, and something to come back with.** The visitor gets the
  case reference and a follow link. A municipality that wants DigiD sends
  the visitor to the portal's own page; the frame never carries a login.
- **Theme and locale** come from the portal the form belongs to, so the
  embed looks like the site it sits on to the extent the portal's theme
  was configured to.
- **No session in the frame.** No Nextcloud cookie, no portal session
  cookie, nothing in the frame that reads a signed-in visitor's data.

## How dossiq consumes it

The register's `dossiq_half`: "nothing beyond the intake binding it
already has; the case arrives through the same contribution contract". No
dossiq change is opened for this row. dossiq declares the intake it
already declares, and a submission from an embed is indistinguishable from
one made on the portal's own page except for the origin recorded on it.

## ADRs

- ADR-046: the form is a contribution; the case app declares, portaliq
  serves.
- ADR-086: the content API is the contract, and the renderer is one
  consumer. The embed consumes the same API.
- ADR-109: the embed runs the one manifest runtime in public boot mode; it
  is not a second front end.
- ADR-108: a citizen-facing form belongs to portaliq, not to a
  `#[PublicPage]` controller in a leaf app.
- ADR-054 and ADR-082: a public surface is hardened and throttled, and an
  embed is the most public surface we have.
- ADR-085: the form itself is a form, authored where forms are authored.

## Existing specs it extends

`portal-contribution-contract` (the anonymous create and its scoping),
`portaliq-cms` (the portal, its domains, theme and locales) and the delta
`portal-shared-runtime` (the public boot mode the frame runs).

## Out of scope

- Rendering without an iframe. Injecting our markup into somebody's page
  means their CSS decides what our form looks like and their scripts can
  read what the visitor types. The frame is the boundary, and it is the
  point.
- A form that shows the visitor their own cases. That needs a session and
  a session does not belong in a third-party frame; the portal's own pages
  are where a signed-in citizen goes.
- Authoring the form. `portal-page-designer` and ADR-085 own that; this
  change publishes a page that already exists.
