---
kind: spec
---

# Proposal: page-public-on-purpose

## Why

buildiq's capability row `acc-public-access` asks that a page can be made available to the public without login, on purpose. Public pages are portaliq's job (the row's provider is portaliq). The main spec already says a draft is never served ("Unpublished content MUST be indistinguishable from absent content") and that only public widgets render at a public origin. No requirement says the other half: that publishing is the act that puts a page in front of anyone, that the editor is told so at that moment, and that the editor can see and withdraw it afterwards. Today the Publish button in the page designer and in the site edit mode publishes without saying who will be able to read the page.

## What changes

One requirement is added to `portaliq-cms`: publishing a page is the deliberate act that makes it readable without signing in. The Publish control says so before it acts, the page list shows which pages anyone can read, and taking a page back to draft makes its route answer as absent again.

No code changes in this change. The tasks name the work.

## Rows covered

- buildiq `acc-public-access` (provider row, portaliq).

## Out of scope

- A page visible to signed-in visitors only. The content API serves anonymous reads by construction; a sign-in-only page is its own change.
- Public apps built in buildiq. buildiq reaches the public through portaliq pages and OpenRegister's anonymous object reads.
