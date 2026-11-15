# Design: public-faq-and-product-finder

## Screens

**Onderwerp** ("Parkeren en verkeer", canvas `5NkFW28vZUUij43xzxHg5a`) ends with "Veelgestelde vragen": question and answer pairs and the link "Alle veelgestelde vragen"; it also has the entry "Zelf uitzoeken wat bij u past" with "Start de productzoeker". **ProductPagina** ("Parkeervergunning bewoners") lists Veelgestelde vragen as an anchor section. Both are the `faqList` block, rendered as an NL DS Accordion with the question as the button and the answer as the panel.

**Productzoeker** ("Productzoeker parkeren"):

| Board element | Here |
|---|---|
| Heading "Welke vergunning past bij u?", intro | finder `title`, `intro` |
| "Vraag 3 van 5", "Nog ongeveer 1 minuut" | position in the remaining questions; 20 seconds per question |
| "Uw antwoorden tot nu toe" with question and answer chips | answers given, each chip goes back to that question |
| Question, help text with "Bekijk de grens op de kaart" | question `text`, `help` (markdown, links allowed) |
| Ja, Nee, Vorige vraag, Volgende vraag, Opnieuw beginnen | radio pair and buttons |
| "Wij bewaren uw antwoorden niet. Sluit u deze pagina, dan begint u de volgende keer opnieuw." | static |
| "Mogelijke producten", "Nog 4 van de 12 producten passen bij uw antwoorden", product cards, "Vallen af door uw antwoorden (8)" | products not ruled out, linking to their pages; ruled-out list folded |

A question whose answer cannot change the remaining set is skipped.

## Data

New schemas in `lib/Settings/portaliq_register.json`, per portal:

- `portalFaq` (schema.org `Question` with `acceptedAnswer`): `portal`, `question`, `answer` (markdown, sanitised like page markdown), `pages` (page routes it belongs to), `topic`, `order`, `status`.
- `portalFinder` (schema.org `Quiz`): `portal`, `title`, `intro`, `products` (page routes), `questions`: list of `{id, text, help, excludesOnYes: [routes], excludesOnNo: [routes]}`, `status`.

Editors manage both as manifest pages in the admin (index plus detail), like the glossary.

## Blocks

- `faqList` widget: props `topic` or `page` (default: the current page's route); reads published `portalFaq` rows through the public content read, ordered by `order`.
- `productFinder` widget: prop `finder` (id); loads one published `portalFinder` and evaluates it in the browser. It sends no request with answers.
- Page `/veelgestelde-vragen` in the example site with `faqList` per topic.
