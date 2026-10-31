# Proposal: editor-blocks-read-public-app-data

## Why

The editor boards (8 October 2026) place blocks on a website page that fill themselves from learniq:

- [vaartveld/Editor](https://identity.conduction.nl/screens/board?id=vaartveld/Editor): "Blok: toetsrooster" with "Afdeling en leerjaar" (4 havo, 5 havo, 4 vwo, "De klas van de bezoeker"), "Toetsweek", "Wat laat u zien?" (lokaal, wat je meeneemt, weging voor het schoolexamen), a table Dag, Tijd, Vak, Lokaal, and "De toetsen komen uit learniq. Een toets wijzigen doet de roostermaker daar. Dit blok volgt vanzelf." The analysis ([vaartveld/Nodig](https://identity.conduction.nl/screens/board?id=vaartveld/Nodig)) marks it "Nieuw".
- [wilgenboom/Editor](https://identity.conduction.nl/screens/board?id=wilgenboom/Editor): "Blok: kalender" with "Kop boven het blok", "Wat laat het blok zien" (vakanties, studiedagen en vrije dagen, activiteiten van school, ouderavonden) and "Hoeveel dagen vooruit" (de eerste vier, dit schooljaar), "De dagen komen uit de schoolkalender in learniq."
- The academy home's "Eerstvolgende cursusdagen" already fills itself (learniq `portal-public-index`).

`site-school-blocks` fixed the `nlEventList` item shape "so that change only adds a `source`" and left the anonymous source out. Lane T's gap list names the exam timetable block; learniq's `portal-public-index` now carries the test schedule and a category per school day.

## What Changes

- `nlEventList` MAY declare `source: {app, kind, categories[], limit | range}`: the items come from the app's public index of that kind, narrowed to the chosen categories and the first N or this school year.
- A new block `nlPublicTable` reads one kind of an app's public index as a table: `source: {app, kind, filters{}}`, `columns[]` chosen from the columns the app declares for that kind, and a filter value `visitor` ("De klas van de bezoeker") that the server resolves from a signed-in visitor's own record and leaves empty for an anonymous one.
- The editor's palette panel lists both blocks with forms for their options; the options come from what the app's public index declares (kinds, categories, filters, columns), not from a list in portaliq.
- Both render server-side from the cached public index; an editor never edits the data.

## Not in this change

- Writing data back: "Een toets wijzigen doet de roostermaker daar".
- Header and footer regions in the editor (deviation D-8).
