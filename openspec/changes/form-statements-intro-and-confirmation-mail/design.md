# Design: form-statements-intro-and-confirmation-mail

## Screens

Canvas `5NkFW28vZUUij43xzxHg5a`.

### FormulierStart

| Board element | Here |
|---|---|
| Heading "Woo-verzoek indienen" and the lead "Vraag de gemeente om informatie die nog niet openbaar is. ..." | `intro.lead` |
| "Voordat u begint": blocks with a title and text, and a list | `intro.blocks[]` (`title`, `text`, `items[]`) |
| "Hoe wilt u verdergaan?", "Met DigiD is het korter en vindt u uw verzoek terug. Zonder DigiD kan ook.", choices with label and explanation, "DigiD", "Start" | the binding's sign-in choices; "Start" opens step 1 |
| "Lukt het niet online? Bel [telefoonnummer]. Wij helpen u verder." | the portal's help phone (`help-texts-and-form-help`) |

A binding without `intro` opens at step 1, as today.

### WooVerzoekControleren

Under the answers, a block "Verklaringen" with "Ik heb mijn antwoorden gecontroleerd en ze kloppen." and "Ik ga akkoord met de verwerking van mijn gegevens volgens de privacyverklaring.", the last words a link to the portal's privacy page. Both unchecked. A missing required statement is an item in the error summary.

### WooVerzoekVerstuurd

| Board element | Here |
|---|---|
| "Bedankt, wij hebben uw aanvraag ontvangen." | `confirmation.title` |
| "Uw verzoek is ontvangen onder kenmerk {kenmerk}. U krijgt uiterlijk {datum} een besluit." | `confirmation.body` with `{reference}` and `{deadline}`; a sentence with an empty value is dropped (as REQ-SMF-022) |
| "Uw kenmerk", "Bewaar dit kenmerk. ..." | the reference block |
| "Wij hebben een bevestiging gestuurd naar {e-mail}, met een samenvatting van uw aanvraag. Geen mail gezien? Kijk ook bij uw ongewenste e-mail." | shown when a confirmation mail was queued |
| "Wat gebeurt er nu?" with numbered steps | `confirmation.next[]` |
| "Download uw aanvraag als PDF", "Naar mijn zaken", "Deze pagina printen" | the receipt PDF; Mijn zaken for a signed-in resident only; `window.print()` |

### PtFormulierInstellingen

Tab "Bevestiging" ("Paginatekst en e-mail met samenvatting en PDF"): `confirmation` texts and `confirmationMail.enabled`. Tab "Verklaringen" ("Privacyverklaring en naar waarheid ingevuld, allebei verplicht"): `statements`. A tab "Algemeen" field "Introductiepagina" toggles `intro`.

## Data

`portalFormBinding` gains:

```json
"intro": { "lead": "", "blocks": [ { "title": "", "text": "", "items": [] } ] },
"statements": { "truth": { "required": true }, "privacy": { "required": true } },
"confirmation": { "title": "", "body": "", "next": [ { "title": "", "text": "" } ] },
"confirmationMail": { "enabled": true }
```

The statement texts are the portal's (one wording per portal, translated), so a submission records `statements: [{ key, textVersion, acceptedAt }]`. `confirmationText` stays as the fallback body.

## Confirmation mail

Sent once the submission has its reference, to the e-mail answer of the form (the verified one when `resident-identity-in-forms` applies). Template `form-confirmation` with `{reference}`, `{formName}`, `{deadline}` and `{summary}`: the visible answers per step as label and value, without file contents, signatures or BSN. The receipt PDF is attached. A refused or bounced mail marks the submission "Bevestiging mislukt" on PtInzendingen, through the existing receipt log.
