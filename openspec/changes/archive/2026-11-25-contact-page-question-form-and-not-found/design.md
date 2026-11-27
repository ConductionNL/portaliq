# Design: contact-page-question-form-and-not-found

## Contact page

Follows the Zuiddrecht board **Contact** ("Site: contact", canvas `5NkFW28vZUUij43xzxHg5a`):

| Board element | Built from |
|---|---|
| "Heeft u een vraag? Kies hoe u ons het liefst bereikt." | `nlParagraph` |
| Card Bellen: number, hours, "Nu bellen" (`tel:` link) | `nlCard` with a button link |
| Card Een bericht sturen: "Ingelogd stuurt u een bericht vanuit Mijn Zuiddrecht. Het antwoord vindt u bij Berichten." "Naar Berichten" | `nlCard`; the button opens the `contactForm` block's page `/contact/vraag` |
| Card Langskomen: address, "Afspraak maken" | `nlCard` |
| Openingstijden table: Dag, Telefonisch, Stadskantoor | `nlTable` |
| "Een vraag over uw eigen zaak? Open de zaak bij Mijn zaken en stuur daar een bericht." | `nlParagraph` with a link |
| Iets melden in de openbare ruimte, "Melding indienen" | `nlCard` |
| Meer contact: Spreekuur wethouders, Klacht indienen, Bezwaar maken, Kwetsbaarheid melden | link list |

All of this is content in `lib/Settings/sites/zuiddrecht.json` page `/contact`; only `contactForm` is new code.

## The contactForm block

Props: `app` and `action` (a contribution create action), `topicField` (the action's enum field used as Onderwerp), `intro`. The block renders Onderwerp as a select from the action's enum values, "Uw vraag" as a textarea, and "Versturen". On success it shows "Wij hebben uw vraag ontvangen. U vindt hem terug bij Mijn vragen." with a link to the contribution's questions collection page.

The block writes through the existing contribution create path (`ContributionController` create), so identity stamping and the action's whitelist apply. Signed out, the block shows "Log in om een vraag te stellen" and the sign-in routes; it never posts without a session.

## Confirmation mail

After a successful create, portaliq queues a mail with template `contact-confirmation` (subject "Wij hebben uw vraag ontvangen") to the account's verified address. The mail names the subject and links to Mijn vragen; it never repeats the question text (no content in mail, as for every notice).

## Not-found page

Follows the board **NietGevonden** ("Site: pagina niet gevonden"). Replaces the 404 branch in `src/site/App.vue:323` with a component `NotFoundPage.vue`:

- eyebrow "Foutcode 404", heading "Pagina niet gevonden", text "Deze pagina bestaat niet (meer). Misschien is het adres verkeerd getypt, of hebben wij de pagina verplaatst."
- "Zoek wat u nodig hebt" with the site search box, when the portal has search
- "Of ga verder naar": De homepage, Mijn Zuiddrecht (when the portal has a resident area), Contact (the portal's `contactRoute`, default `/contact`, hidden when that route does not exist)
- "Kwam u hier via een link op onze website? Laat het ons weten via Contact, dan herstellen wij de link."

The page keeps `data-portaliq-status="404"` and the identical answer for unpublished and never-existing routes (`site-unpublished-hidden`).
