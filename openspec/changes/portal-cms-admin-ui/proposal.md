---
kind: code
---

# Proposal: portal-cms-admin-ui

## Summary

Give Portaliq an editorial surface for websites, menus, pages and glossary
terms, so a human can run a site without `curl`. **This must ship before
`cms-handover`**, which removes OpenCatalogi's CMS UI.

## Motivation

The content model, the API, the caching and the renderer are built and
verified. The way content gets created is a shell script posting JSON at
OpenRegister's object API — that is how every fixture in
`tests/e2e/fixtures/seed-cms.sh` exists.

That is fine for a rig and unusable for a municipality. More importantly, the
chain currently sequences `cms-handover` — which deletes OpenCatalogi's 31
menu/page/glossary frontend files — with no replacement anywhere. Shipping in
that order leaves the fleet with **no way to edit a menu at all**.

The ordering, not the UI, is the finding here. Everything else in this
programme degrades gracefully if it lands late; this one combination
regresses a capability users have today.

## Affected Projects

- [ ] `portaliq` — admin views for `website`, `menu`, `page`, `glossaryTerm`;
      publish-time validation; a domain-verification trigger.
- [ ] `hydra` — `cms-handover` gains this as an explicit precondition.

## Design notes

**Built on the shared abstractions, not hand-rolled.** These are OpenRegister
objects; the admin surface is `CnIndexPage`/`CnDetailPage` over them with the
existing manifest-driven Vue admin SPA. Portaliq already ships that SPA — this
is pages in its manifest, not a new front-end.

**Publish-time validation is where the product lives:**

- A menu item linking to a route with no published page is refused, or warned
  about loudly. The rig hit exactly this: `/begrippen` was in the menu with no
  page behind it, and the only symptom was a console 404 on a page that
  otherwise rendered fine.
- A website with no page at `/` is refused at publish. `open-venray` currently
  404s on its own front door.
- A page route must be unique within its website.

**The domain-verification trigger belongs here** — `portal-website-scoping-and-auth`
implements the DNS check; this is the button that runs it and shows the TXT
record to publish.

## Risks

- **A second editing surface during the transition.** For one release both
  OpenCatalogi's UI and this exist. `cms-handover` removes OC's immediately on
  landing, so the window is short — but during it, two places edit the same
  objects and the newer one must be the one people are sent to.
- **Publish-time validation can be too strict.** Refusing a menu item whose
  page is drafted but not yet published would block a normal editorial
  workflow; the rule needs to distinguish "no such page" from "not published
  yet".
