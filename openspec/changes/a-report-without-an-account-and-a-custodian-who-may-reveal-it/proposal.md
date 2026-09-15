---
kind: code
depends_on: [portal-intake-form-as-an-object, portal-identity-and-the-organisations-cases]
---

# Proposal: a-report-without-an-account-and-a-custodian-who-may-reveal-it

## The row this closes

**13.33**, area Access and privacy, rated `no` for dossiq: "Report accepted
without an account, unmasked only by a named custodian."

Source field, verbatim: `dossiq#2314, published as 13.26`.

Corpus batch file, `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
in ConductionNL/market-intelligence, the table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **13.33** | 13.26 | Report accepted without an account, unmasked only by a named custodian | no | unread |  |
```

The ledger note, verbatim:

> The Wet bescherming klokkenluiders obliges a municipality to run exactly this surface. Nothing accepts a report against a receipt code, and nothing gates the reveal on a motivated request a custodian answers.

## What the competitor evidence is

There is none, and the corpus says so in as many words. The row is one of the
98 promoted under decision D1, and the batch file's own preamble reads:

> **Every competitor column is `unread`, and none of them is `no`.** The corpus columns are GLPI, Zammad, OpenProject, Plane, Redmine, Forgejo, osTicket, FreeScout, Znuny, GitLab, OTOBO, iTop, Odoo, Deck, Kanboard, Vikunja, RT, Helpdesk, Gitea, Taiga, Tuleap, Huly, JSM, YouTrack, Jira DC, Easy Redmine, OpenCase, GZAC, xxllnc Zaken and Dimpact ZAC.
> Not one of them has been read against a row below. `unread` is what the ledger writes for that, and the distinction is the whole point: `no` is a reading of a product somebody opened, and filling these cells with it would fabricate thirty readings per row.

The row carries no cross-reference. The batch file records what is nearest:

> **Nothing near it in the corpus.** Nearest corpus row 1.1, the citizen web form, which carries no custodian.

## Why the row is open

Nothing in this repo accepts a submission from someone who will not say who
they are and then lets them come back for the answer.

`portal-identity-and-the-organisations-cases` opened a second identity kind,
`reference`: a case number plus a verified e-mail address. That is one door
short of this row. A reporter under the Wet bescherming klokkenluiders (Wbk)
often gives no e-mail address at all, because an address is an identifier and
the whole point is that nobody holds one.

The archived `2026-07-23-wmebv-submission-receipts` writes an
ontvangstbevestiging into the submitting subject's inbox. Here there is no
subject and no inbox. The receipt is the only thing the reporter leaves with.

## Who has to run this surface

Not only municipalities. The Wbk obliges every employer with fifty people or
more to run an internal reporting channel, keep the reporter's identity out of
the hands of anyone who has not been named to hold it, and come back to the
reporter within the law's terms. Decision D17 says the market is municipalities
and MKB, and this row is the clearest case of it: the duty is the same for a
gemeente of nine hundred and a bouwbedrijf of sixty.

So the surface is declared per portal, by whoever runs it, and nothing in the
mechanism assumes a gemeente.

## What portaliq builds

- **A report accepted with no account.** No login, no e-mail address, no
  verification step in front of the report.
- **A receipt code the reporter keeps.** Shown once, on screen, with the
  warning that it cannot be re-sent. It is the only key back in.
- **A two-way conversation against that code.** The custodian asks, the
  reporter answers, and the reporter reads the outcome. Both sides see the
  same thread and neither side needs a name for it.
- **Identity held apart from the report.** Where a reporter chooses to give
  contact details, they are stored apart from the report body and are not
  shown with it, not in a list, not in a search result and not in an export.
- **A reveal only a named custodian can make.** The custodian is named in the
  declaration, the request that asks for the reveal carries a motivation, and
  the reveal is recorded with who asked, who allowed it and why.
- **Terms the reporter can see.** The Wbk sets seven days for the
  acknowledgement and three months for the feedback. The portal reads both
  from the declaration rather than holding them as constants, so a change in
  the law is a declaration change.
- **A channel that leaks nothing sideways.** No third-party challenge, no
  visitor analytics on the reporting surface, and no client address recorded
  against a report.

## How dossiq consumes it

dossiq declares the report as a case type: which custodian role may reveal,
what the acknowledgement and feedback terms are, and which fields are shown
back to the reporter. It never renders the surface and never holds the
credential. A reveal reaches it as an event, and the identity it then holds is
the case app's to keep under its own retention rules.

No dossiq change on `development` carries that half today. It is to be
specified in dossiq. The nearest existing change is `sensitive-fields-declared`,
which declares that a field is sensitive; it says nothing about a reporter
without an account and nothing about a custodian.

## Existing specs it extends

`portal-intake-form-as-an-object` (this repo), for the form the report is
submitted on, and `portal-identity-and-the-organisations-cases`, whose
identity kinds this change joins with a third way in that carries no address.
It also extends `portal-auth-edge-session-hardening` for the session the code
opens, and `portal-contribution-contract` for the declaration and the events.

## ADRs

- ADR-046: the case app declares the custodian, the terms and the fields.
  portaliq serves the surface and holds no policy.
- ADR-108: a citizen-facing surface belongs in portaliq, not in the case app.
- ADR-054 and ADR-082: the reporting surface is public, so it is hardened and
  throttled, and the receipt code is signed and rate-limited like any other
  credential whose only protection is that it is hard to guess.
- ADR-041: a reveal reaches the case app as a typed event.
- ADR-023 (portaliq): revealing is an action, authorized as an action. Reading
  the report does not carry it.
- ADR-047: erasure and retention of a revealed identity stay OpenRegister's
  workflow. This change records the reveal, it does not re-implement the
  statutory rights over it.

## Size

L. A public intake surface, a credential with no account behind it, a
two-way thread, a separated identity store and an authorized reveal.

## Out of scope

- Investigating the report. That is a case, and the case app runs it.
- The external channel at Huis voor Klokkenluiders. This is the internal one.
- Anonymising a document a reporter attaches. filinq owns anonymisation.
