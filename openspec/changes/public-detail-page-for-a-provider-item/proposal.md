# Proposal: public-detail-page-for-a-provider-item

## Why

Two school websites open an item of the public catalogue on a page of its own (8 October 2026), and `portal-public-catalogue` leaves that out ("A detail page for a course or a programme: an item links where the app says, or nowhere"):

- [warmtepompacademie/Artikel](https://identity.conduction.nl/screens/board?id=warmtepompacademie/Artikel): "Cursus: F-gassen, herhaling en examen": facts (certificaat, 1 dag), "Kies een datum" as date cards with the places free ("Op deze dag zijn 7 plekken vrij"), the programme of the day, "Meenemen", and an enrol card: "Aantal deelnemers" (the `count` field), "3 deelnemers inschrijven", "De namen vult u in de volgende stap in", "Incompany aanvragen". Plan gap G-17.
- [esdoornveen/Artikel](https://identity.conduction.nl/screens/board?id=esdoornveen/Artikel): "Opleiding: Mechatronica niveau 4" with a facts list (niveau, duur, leerweg, start, locatie, crebonummer), "Wat leer je?", a week table per year, "Toelating", "Na je diploma", and "Aanmelden voor Mechatronica".

The analysis ([warmtepompacademie/Nodig](https://identity.conduction.nl/screens/board?id=warmtepompacademie/Nodig)) marks "Cursus met inschrijven" "Ontbreekt". Lane T2 notes it is partly covered by `count-field` and learniq `employer-portal-audience` (the enrol action exists for a signed-in employer, on a contribution page).

## What Changes

- **A public detail page per catalogue item**: a contributing app MAY declare a `publicDetail` for an item kind of its public index; portaliq serves it at `/{route}/{slug}` with the item's facts (a description list), sections of authored text the app projects (markdown), a `dates` list (date cards with a places line and a selected state) and optional linked documents.
- **An action that needs sign-in**: the page MAY place one of the app's actions with `requiresSignIn: true`; a visitor who is not signed in sees the action's card and a button "Inloggen om in te schrijven" that returns to the same page, with the chosen date and count kept, after sign-in with a mode that may perform it. A signed-in person without the right audience reads why in words.
- **A secondary link** (such as "Incompany aanvragen") as an `nlButtonLink` the app projects.

## Not in this change

- Editing these pages in the editor: they are the app's, generated from its data. An editor may link to them.
- Prices: learniq `academy-invoices-on-the-portal` decides where they come from; until then `[PRIJS]` as the app sends it.
