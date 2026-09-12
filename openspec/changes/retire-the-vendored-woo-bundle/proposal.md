---
kind: mixed
---

# Proposal: retire-the-vendored-woo-bundle

## Summary

Stop serving one municipality's pre-built React application out of the fleet's
generic portal app, and author the same public surface as Portaliq content: a
`portal` with its own domain and theme, `menu` and `page` rows, and the public
block vocabulary the site renderer already ships. `WooController`, the
`/woo` routes and the 28 MB `woo/` directory all go once the content exists.

## Motivation

`woo/` is 28 MB of build output from `tilburg-woo-ui`, committed into this
repository and served by a controller whose own docblock describes it as "the
standalone-SPA pattern, served in-app". Three things are wrong with that, in
increasing order of seriousness.

**It is one customer inside a generic app.** Portaliq is the fleet's shared
external portal. Tilburg's site is a tenant of it, not a part of it. Every
other municipality that wants a WOO surface either gets Tilburg's build or gets
nothing, which is exactly the outcome the portal/menu/page content model exists
to avoid.

**It is a fourth frontend.** This repository already builds three: the admin
Vue SPA, the React portal at `/portal`, and the Vue site renderer at `/site`.
`woo/` adds a fourth, with its own React tree, its own routing, its own auth
screens and its own theming, none of which Portaliq can configure, theme or
even read. A theme change in Portaliq cannot reach it. A menu edit cannot
reach it. It is opaque to every capability the app has.

**It is checked-in build output.** 28 MB of hashed bundles, fonts and images,
with no build step in this repo that reproduces them. Nothing here can rebuild
`woo/` from source, so it can only ever be replaced by copying another build
in. That is not a dependency, it is a snapshot.

## What makes this feasible now, and what does not

This is not a rewrite of `tilburg-woo-ui`. Most of its 25-odd routes are not
public-site concerns at all, and Portaliq already owns better mechanisms for
them.

**The public surface is already expressible.** The site renderer's public block
vocabulary is `markdown`, `hero`, `search`, `section`, `cardGrid`, `card`,
`emptyState`, `glossary`, `contributions`, `federatedSearch` and
`publicationDetail`. Mapped against the WOO routes:

| WOO route | Portaliq expression | Status |
|---|---|---|
| `/` home | `hero` + `cardGrid` + `search` on a page grid | blocks exist |
| `/zoeken` search | `federatedSearch` | block exists |
| `/publicatie/:id` | `publicationDetail` | block exists |
| `/onderwerpen` themes | `section` + `cardGrid` | blocks exist |
| `/gemma` | a markdown page | block exists |

**The non-public surface is not content, and must not become content.**
`/login`, `/register`, `/reminder` and `/authorization` are an auth edge that
Portaliq already owns properly, through DigiD, eHerkenning and eIDAS with three
trust levels. `/mijn-omgeving` is the authenticated portal, which is
`/portal` plus the contribution aggregate and the unified inbox. The nine
`/forms/*` routes are portal-contribution `actions` with schema-driven fields.
`/beheer/*` is administration, which belongs in the admin SPA behind Nextcloud
auth, not on a public origin.

So the work splits cleanly: author the public pages as content, and route the
rest at capabilities that already exist. Nothing here needs a new renderer.

**What is genuinely missing** is the honest part of this proposal, and it is
why this is filed as a change rather than done directly. The WOO surface reads
publications from a register with facets and full-text search; `federatedSearch`
and `publicationDetail` need to be pointed at the real WOO register and proven
against real volumes, not at a demo row. Until that is measured, the table
above says "the block exists", not "the page is done".

## Non-goals

- Rewriting `tilburg-woo-ui` itself. It stays where it is; it simply stops
  being served from inside Portaliq.
- Reproducing `/beheer`. Administration is not a public-site surface.
- Any change to the auth edge. It already does more than the bundled screens.

## Risks

**Tilburg is live.** The bundle is served today, so nothing may be deleted
until the authored portal serves the same public surface on the same paths, or
the redirect story is agreed with the municipality. The task order below puts
every deletion last for that reason.

**Deleting 28 MB is not reversible by a revert alone.** Once the directory
leaves, restoring it means finding the build again. That is an argument for
doing it, but it means the deletion step needs a real sign-off, not a green
pipeline.

## Related

- ADR-046, the portal contribution and auth edge.
- ADR-084, the public host.
- ADR-086, the portal content model.
- `portal-shared-runtime`, which removes the React portal at `/portal`. That
  change and this one both reduce this repo to one frontend; they are
  independent and can land in either order.
