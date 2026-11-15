# Proposal: the Lindelaan Woo case has sub-cases, a subject case and objects

## Why

The Gerelateerd tab of the case (board DqZaakGerelateerd) draws three things on the Lindelaan Woo case: Gerelateerde zaken, with "Onderwerp van deze zaak: Aanleg fietspad Lindelaan" (an omgevingsvergunning closed last December); Deelzaken, with "Zienswijze Aannemersbedrijf Van Wijk" (Lars de Koning) and "Lakken offerte lantaarnpalen" (Pieter Jansen); and Objecten, with the cycle path and two lamp posts. The case had none of them, so the tab drew three empty states.

## What changes

- `omgevingsvergunning-fietspad`: the closed omgevingsvergunning "Aanleg fietspad Lindelaan", placed before `woo-fietspad` so the Woo case can name it.
- `woo-fietspad` names it as its subject: `subjectCases` and the RGBZ `relatedCases` entry with `aardRelatie: subject`, the two places CaseRelationService writes a subject link.
- Two sub-cases under `woo-fietspad` (`parentCase`), on the Woo case type, with a handler and a deadline.
- Three `caseObject` links: the cycle path (BGT wgl.0505.1234) and lamp posts LM-0014 and LM-0015.

## Not in this change

- The object records themselves. The Object and Object status columns are lenses through `objectUrl` onto a record in an objects register, and no register in the fleet holds public-space objects yet. The links carry type, identification and description; the two lens columns stay empty until such a register exists.
- "Wacht op: Advies verlichting fietspad" (a waits-on case) and the planned follow-ups: the advice is an `adviesAanvraag` record, not a case, and planned flows are not example-set data.
