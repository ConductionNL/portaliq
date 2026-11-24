# Design: case-page-objection-and-complaint

Read at portaliq `development` `6cc375af`. Screen: board `Zaak` ("Mijn Zuiddrecht: uw zaak") on the Zuiddrecht canvas `5NkFW28vZUUij43xzxHg5a`, page `dcb81aee8d83`. Local copy: `~/memcap-work/zuiddrecht/v2/project/Zaak.dc.html`.

## What the board draws

Top to bottom, on the case page:

1. "Uw zaak · 2026-0082", the title, "Terug naar mijn zaken".
2. The status notice, "Waar staat uw aanvraag?", "Gegevens", "Stukken" with "Document toevoegen".
3. "Een vraag over deze zaak?" with "Uw bericht" and "Bericht sturen" (dossiq `communication-portal-conversation-on-the-case`).
4. A button group with the accessible name "Meer acties voor deze zaak": "Bezwaar maken", "Klacht indienen", "Wijziging voorstellen", "Aanvraag intrekken".

This change owns the first two buttons of item 4. They are secondary buttons (2px border in the primary colour, white fill), in the order the contribution declares them, before "Wijziging voorstellen" and "Aanvraag intrekken". Withdraw keeps its own red tone.

## D1. The contract key: `onCase` on a create action

```json
{
  "id": "createBezwaar",
  "type": "create",
  "label": "Bezwaar maken",
  "crossRefs": { "againstCaseId": { "collection": "zaken" } },
  "onCase": {
    "crossRef": "againstCaseId",
    "order": 10,
    "window": { "from": "decisionSentAt", "days": 42 },
    "closedText": "De termijn voor bezwaar is voorbij."
  }
}
```

- `crossRef` MUST name a key of the same action's `crossRefs`. The collection it points at is the case collection whose page shows the button.
- `window` is optional. Without it the button shows whenever the case is the resident's own.
- `closedText` is optional. Default: "You can no longer do this on this case." (nl: "Dit kan niet meer bij deze zaak.").
- A malformed `onCase` is dropped and the action stays where it was. This is the opposite of `crossRefs`, which drops the whole action. `onCase` only adds a placement; dropping it leaves the guarded action as it was.

Normaliser: `lib/Contribution/OnCaseConfigNormaliser.php`, called from `ActionConfigNormaliser` after the cross-ref normaliser.

## D2. The server decides open or closed

`CitizenCaseController::show()` adds `caseActions` next to `withdrawal` and `documents`:

```json
"caseActions": [
  { "app": "dossiq", "action": "createBezwaar", "label": "Bezwaar maken", "open": true, "closesOn": "2026-11-14" },
  { "app": "dossiq", "action": "createKlacht", "label": "Klacht indienen", "open": true }
]
```

A new service `lib/Service/CaseActionsResolver.php` reads the resident's contribution, keeps the create actions whose `onCase.crossRef` points at this case's collection, and evaluates the window. The window opens the day after the `from` date and closes after `days` days, at the end of that day in Europe/Amsterdam. A missing or unreadable `from` value means closed, with the closed sentence. The screen never computes a date.

## D3. The form leaves out the case field

The case screen opens the action's form in place, under the button group, with focus on its heading. It renders the action's fields minus the `crossRef` field. The submit goes to the existing create route with a new query parameter `onCase=<register>/<schema>/<id>`.

`ContributionController::create()` with `onCase`:

1. Re-reads the case through the scoped reader (the same read the case page used).
2. Re-evaluates the window. A closed window answers 409 `case_action_closed` with the sentence.
3. Writes the case id into the `crossRef` field, overwriting anything in the body.
4. Runs `PortalCrossRefGuard` as before. The guard is never skipped.

## D4. After submitting

The form is replaced by a success notice: "Uw bezwaar is ontvangen. Kenmerk {reference}." and "U vindt het als eigen zaak in Mijn zaken." The reference comes from the existing submission receipt (`SubmissionReceiptService`). The button stays, because a resident may file a second klacht.

## D5. The overview line in `zuiddrecht-resident-pages-match-the-boards`

That change says "Bezwaar maken and Klacht indienen MUST be on the overview". Once dossiq declares `onCase`, the Zuiddrecht pages carry them on the case page instead. Task T08 updates `tests/zuiddrecht-resident-boards.spec.mjs` to expect them there. A portal whose contribution declares no `onCase` keeps the overview placement.

## Risks

- **Date field drift.** If dossiq renames its decision date, every bezwaar window reads closed. That fails safe, and the unit test of dossiq's provider pins the field name.
- **Two forms for one action.** The overview form and the case form exist side by side until dossiq removes the overview declaration. Both run the same guard.
