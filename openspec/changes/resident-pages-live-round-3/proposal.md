# Proposal: resident pages after live round 3

## Why

Live round 3 on the Zuiddrecht demo (14 Oct, screenshots in the programme's `sc3/shots`) found five things a resident or a visitor trips over:

1. A date asked as Dag, Maand and Jaar kept only "01" when "01-01-2026" was typed or pasted into Dag.
2. Mijn zaken opened on "Mijzelf" for a resident who works for others, and "Iedereen voor wie ik werk" on a first visit read only the resident's own cases: the mandates were not known yet when the list was read.
3. "Alle vergaderingen", "Alle besluiten" and "Alle raadsleden" on a council record went to a bare `/index.php/apps/portaliq/site`, losing the portal and the page.
4. A publication's breadcrumb read "Home › Publicatie › Publicatie".
5. Every Woo form open logged a 404 on `portal/api/drafts/...`: the action declares a draft, but it is an endpoint action and the server keeps drafts only of create actions.

## What changes

- The date group splits a whole date typed or pasted into one box across the three, keeps digits only, and moves to the next box after two typed digits (or a separator after one). Only typing moves focus.
- Mijn zaken opens on everyone unless a party was chosen this session; reading everyone reads the resident's own list first, learns the mandates from it, then reads each.
- The back link on a council record keeps the portal, the route and the language (`addressOfRoute`).
- The publication detail tells the page its title, which ends the breadcrumb.
- A form asks for a draft only when its action is a create action with a register and schema; the drafts endpoint answers "no draft" with a quiet 200 `{found: false}`.

## Not changed here

- The "Mijn zaken: My cases, My tasks, My messages" group in the resident menu comes from zaakafhandelapp's portal contribution on the dev instance; zaakafhandelapp is not on the tenant and is out of scope.
- A slow first sign-in is Nextcloud copying its 63 MB `core/skeleton` into the new account; the instance config fixes that, not portaliq.
