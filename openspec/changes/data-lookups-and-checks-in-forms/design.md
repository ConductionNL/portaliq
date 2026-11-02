# Design: data-lookups-and-checks-in-forms

## Screens

Canvas `5NkFW28vZUUij43xzxHg5a`.

### FormulierVelden

| Board element | Here |
|---|---|
| "U vult uw postcode en huisnummer in. Wij zoeken de straat en plaats erbij.", Postcode, Huisnummer, Toevoeging (niet verplicht), then "Wij vonden dit adres bij uw postcode en huisnummer. Klopt het niet? Pas de straat of plaats dan zelf aan.", Straat, Plaats | field type `addressNL`, lookup on when the binding's `addressLookup` is true |
| Second block "Adres van het pand" with Huisletter | the same type with `houseLetter: true` |
| "Wij controleren of u eigenaar bent", "Volgens het Kadaster staat dit pand op uw naam. Dat is nodig voor deze aanvraag." | a `fetch` step result shown as a check line |
| "Burgerservicenummer (BSN)", "Uit DigiD. U kunt dit niet wijzigen." | `format: bsn`, read-only when prefilled from the session |
| "IBAN", example text, error "Dit IBAN klopt niet. Controleer de cijfers." | `format: iban` |
| "Kenteken", "Waar vind ik mijn kenteken?", "U mag het met of zonder streepjes invullen." | `format: nl-licence-plate`, stored without dashes in capitals |
| "Telefoonnummer (niet verplicht)" | `format: phone-nl` or `phone-international` |
| "Soort woning", "De keuzes komen uit de referentielijst woningtypen van de gemeente." | `options.referenceList: "woningtypen"` |

### FormulierGezinsleden

| Board element | Here |
|---|---|
| "Wie verhuist er met u mee?", "Uw partner en kinderen die op hetzelfde adres wonen." | field type `familyMembers` with `relations: ["partner","children"]`, `sameAddressOnly: true` |
| "Wij vonden deze personen op uw adres", "Ze staan op Lindelaan 12 ingeschreven in de Basisregistratie Personen. Kies wie er met u meeverhuist." | intro line with the resident's address |
| Cards "Henk de Vries, Partner, geboren in 1983" with initials | one checkbox card per person: name, relation, birth year only |
| "Staat er iemand niet bij of klopt een gegeven niet? Dat regelt u niet in dit formulier. Neem contact met ons op, dan zoeken wij het uit." | fixed line under the cards |

The communication preferences half of this board belongs to `cmp-id-contact-channel`, not here.

### FormulierNietBeschikbaar, situation 4

"Er is een storing", "Wij kunnen uw gegevens nu niet ophalen bij een ander systeem. Daardoor kunt u dit formulier nu niet afmaken. Probeer het later opnieuw.", "Uw concept blijft bewaard", "Opnieuw proberen", "Naar Mijn Zuiddrecht". Shown inside the step when a fetch or a prefill fails.

### Price options

No board. Until one exists: radio cards (the `choices` widget of `site-multi-step-forms`) with the variant's name, one line and its price, "€ 38,50 per jaar"; the review step repeats the chosen variant and price.

## Server

- Address: `GET /api/intake/address?postcode=&number=&letter=&addition=` answers street and town from OpenRegister's BAG register through ObjectService, or 404. Public (forms can be anonymous) and throttled per client. The site calls it on blur of the house number.
- Formats: `lib/Service/Intake/DutchFormats.php` with `bsn` (elfproef), `iban` (ISO 13616 mod 97), `nl-licence-plate` (RDW side codes 1 to 14, with or without dashes), `phone-nl`, `phone-international` (E.164), `postcode` (1234 AB, the space optional), `kvk` (8 digits), `kvk-branch` (12 digits). `PortalFormValidator` calls it for a field with `format`; the site mirrors it in `src/site/components/forms/formats.js` on blur. Messages in nl and en per format.
- Reference lists: `options.referenceList` names a list held in OpenRegister (one object per item with `code`, `label`, `active`). The resolver fills the field's options at render, active items only, cached for an hour; the validator refuses a value outside the list.
- Family: `GET /api/intake/{route}/family` for a DigiD session only, through `BrpPersonProvider`: partner and children, filtered on the same address when asked, returning a person reference, name, relation and birth year. On submit the server checks each chosen reference against the BRP again.
- Fetch: a form step MAY declare `fetch: [{ id, source, inputs, outputs, show }]`. At the step change `POST /api/intake/{route}/steps/{step}/fetch` calls the integriq source with the named answers and writes the outputs into read-only fields or a check line. The browser never calls the outside service. A failure shows situation 4.
- Price options: field type `productVariant` with `product` (a product of the portal's catalogue). Its options are that product's variants from the catalogue's Kosten block (name, price, per). The pay step of `intake-pay-on-submit` takes the chosen variant's price from the catalogue on the server.
