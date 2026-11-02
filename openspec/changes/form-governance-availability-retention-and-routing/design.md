# Design: form-governance-availability-retention-and-routing

## Screens

### PtFormulierInstellingen (admin)

The settings page of one form binding, canvas `5NkFW28vZUUij43xzxHg5a`. This change builds the tabs it owns and the status panel.

| Board element | Here |
|---|---|
| Header "Woo-verzoek", "Formulier bekijken", "Meer", "Opslaan", tag "Gepubliceerd", "Inwoner" | the binding's form name, status and audience; "Meer" holds "Inzendingen downloaden" |
| Tab "Beschikbaarheid" (summary "Actief vanaf 1 januari 2026, geen einddatum, maximaal 500 per maand, onderhoudstekst ingesteld") | `availability` |
| Tab "Bewaartermijn" (summary "Voltooid 30 dagen, onvolledig 30 dagen, mislukt 90 dagen, daarna anonimiseren") | `retention` |
| Tab "Doorsturen" (summary "Naar dossiq; gaat het over milieu, dan naar milieu@zuiddrecht.nl") | `delivery` |
| Status panel "Inzendingen deze maand 41 van maximaal 500" | the counter of the current period |

### FormulierNietBeschikbaar (resident)

| Situation | Text on the board | Source |
|---|---|---|
| 1, maintenance | "Dit formulier is even niet beschikbaar", the window, "Was u al bezig? Uw opgeslagen antwoorden blijven bewaard." | `availability.maintenance` |
| 2, replaced | "Dit formulier is niet meer beschikbaar", "Naar het nieuwe Woo-verzoekformulier" | `availability.replacedBy` (a route on the same portal) |
| 3, limit reached | "Er kunnen nu geen aanvragen meer bij", "alle 150 plekken vergeven", "Vanaf maandag 5 januari 2027 kunt u weer een aanvraag doen." | `availability.limit`, `availability.reopensOn` |

Before `activeFrom` the route shows situation 3's layout with "Dit formulier opent op {datum}." After `activeUntil` without a replacement it shows "Dit formulier is niet meer beschikbaar" and the portal's contact line.

### FormulierVerwerken (resident)

Situation 1 is the existing reference page while the queue creates the case. Situation 2 is new: "Het versturen is niet gelukt", "Uw antwoorden zijn bewaard", the date the answers are kept until (from `retention.failedDays`), "Opnieuw proberen", "Neem contact op" and the error code. The error code is `OF-` plus the first four characters of the submission reference, so the desk can find it without the resident reading out a UUID.

### PtInzendingen and PtInzending (admin)

The list keeps its columns; the filter "Bevestiging mislukt" gains a sibling "Doorsturen mislukt". A failed submission shows "Opnieuw doorsturen" on its own page, next to the evidence log, which records each attempt.

## Data

`portalFormBinding` gains (schema bump, import checked for `PARTIAL IMPORT`):

```json
"availability": { "activeFrom": "2026-01-01", "activeUntil": null,
  "limit": { "count": 500, "per": "month" }, "reopensOn": null,
  "maintenance": { "from": "2026-10-09T18:00", "until": "2026-10-09T22:00", "text": "" },
  "replacedBy": null },
"retention": { "completedDays": 30, "incompleteDays": 30, "failedDays": 90, "method": "anonymise" },
"delivery": { "default": { "kind": "caseType" },
  "rules": [ { "when": { "field": "onderwerp", "op": "eq", "value": "milieu" }, "target": { "kind": "email", "address": "milieu@zuiddrecht.nl" } } ] }
```

`limit.per` is `total`, `month` or `year`. `delivery` target kinds are `caseType` (the binding's case type app, the existing path), `email` and `integriq` (`{ "kind": "integriq", "source": "<connection id>" }`). `when` uses the comparisons `visibleWhen` already accepts (`VisibleWhenComparison`).

## Counting

The counter is the number of `portalIntakeSubmission` objects for the binding in the period, read through OpenRegister's count with a filter on binding and `submittedAt`. The submit path checks it inside the same request that records the submission; a race past the limit by one is accepted and logged, never a refused resident after the confirmation.

## Retry

`PortalIntakeQueue` already records `failed`. A background job retries a failed delivery after 5 minutes, 1 hour and 6 hours. The resident's "Opnieuw proberen" and staff's "Opnieuw doorsturen" queue one attempt at once. Each attempt is a line in the evidence log. After the third background failure the submission stays `failed` and goes into the digest.

## Digest

A daily job at 07:00 mails the portal's administrators (the portal's admin group) one message listing, for the past 24 hours: failed deliveries, failed confirmations, failed prefills and bindings that open no form (the "Opent niets" count on PtAanvraagformulieren). No mail when the list is empty. Template `form-failure-digest` in the mail templates screen.

## Retention

On every state change the submission object gets an expiry date from the binding: completed from `completedDays`, unfinished drafts from `incompleteDays`, failed from `failedDays`. A daily job deletes expired objects through OpenRegister, or with `method: anonymise` replaces the answers with an empty object and keeps reference, binding, dates and state. A binding without `retention` keeps the defaults 30, 30 and 90 days.

## Export

"Inzendingen downloaden" under "Meer" asks for a period and a format (CSV or XLSX) and downloads the visible answers of the binding's submissions in that period, one column per field, plus reference, submitted at and state. Only a user who may manage the binding gets the action. Each export writes an audit line with who, which binding, which period and how many rows. The PtInzendingen "Downloaden" exports the list columns only, without answers.
