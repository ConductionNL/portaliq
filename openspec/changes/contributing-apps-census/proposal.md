---
kind: code
---

# Proposal: contributing-apps-census

## Why

The portal only shows what sibling apps contribute. `PortalProviderLocator::locate()` finds an app's `OCA\<App>\Portal\PortalContributionProvider` by convention, and an app without one simply contributes nothing, without a word. A git grep on 30 Oct found that class in 11 of the fleet's 21 core apps. Nobody can see that on an installation: an administrator who wonders why the portal shows no permits cannot tell whether the permit app is missing, contributes nothing, or contributes to another audience. The contract spec describes how a provider is consulted ("Multi-audience provider discovery"), but no requirement makes adoption visible.

## What changes

- **A census of contributing apps.** `occ portaliq:contributions` lists every enabled app with one line each: whether it ships a provider, the audiences it serves, and how many collections, pages and actions its contribution declares for a test subject of each audience. An app with no provider reads "contributes nothing".
- **Opencatalogi is named as its own pattern.** It integrates through its public search and publication endpoints, not a provider, and the census says so instead of listing it as missing.
- **JSON for scripts.** `--output=json` gives the same rows, so the fleet sweep can read adoption per installation instead of grepping repositories.

## Rows covered

- `con-fleet-adoption` (built in 11 apps; the census is this change). No screen of its own.

## Out of scope

- Writing providers for the apps that lack one. Each app owns its own provider.
- An admin page for the census. The command is enough to answer the question; a page needs a board first.
