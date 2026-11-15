---
kind: code
depends_on: [what-the-citizen-may-write-on-their-own-case, portal-status-transitions]
---

# Proposal: withdrawing-your-own-case-from-the-portal

## The row this closes

**2.47**, area Case core, rated `partial` for dossiq: "Applicant withdraws
their own case from the portal."

Source field, verbatim: `dossiq#2314, published as 2.41`.

Corpus batch file, `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
in ConductionNL/market-intelligence, the table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **2.47** | 2.41 | Applicant withdraws their own case from the portal | partial | unread | corpus 6.7 |
```

The ledger note, verbatim:

> ComplaintService has a withdrawn status, set internally by a handler. No case type lets the applicant close their own case from the portal.

## What the competitor evidence is

There is none, and the corpus says so in as many words. The row is one of
the 98 promoted under decision D1, and the batch file's own preamble reads:

> **Every competitor column is `unread`, and none of them is `no`.** The corpus columns are GLPI, Zammad, OpenProject, Plane, Redmine, Forgejo, osTicket, FreeScout, Znuny, GitLab, OTOBO, iTop, Odoo, Deck, Kanboard, Vikunja, RT, Helpdesk, Gitea, Taiga, Tuleap, Huly, JSM, YouTrack, Jira DC, Easy Redmine, OpenCase, GZAC, xxllnc Zaken and Dimpact ZAC.
> Not one of them has been read against a row below. `unread` is what the ledger writes for that, and the distinction is the whole point: `no` is a reading of a product somebody opened, and filling these cells with it would fabricate thirty readings per row.

The cross-reference is corpus 6.7, messaging with citizens through a portal.
The batch file records it as a neighbour, not as the same question: "itop and
jsm lanes cite `6.7, 2.41 in dq`". 6.7 is the inbox this repo already shipped.
Withdrawal is an act on the case, not a message about it.

## Why the row is open

dossiq has a `withdrawn` status. Only a handler can reach it. A citizen who
wants to drop their own request phones the desk, and a clerk sets the status
for them. That is the `partial` in the ledger, and it is the whole gap.

Nothing in this repo carries it either. `what-the-citizen-may-write-on-their-own-case`
gives the citizen three acts: amend an answer, add a document, answer a task.
All three are writes inside a running case. Withdrawal ends the case, which is
a different decision with a different authority behind it.
`portal-status-transitions` has the mechanism, a server-fixed transition target
on a row action, and names no citizen and no case.

## What portaliq builds

- **A withdraw action offered only when the case type declares it.** The
  portal decides nothing. It reads the declaration and renders what it finds.
- **A window that closes.** A request can be withdrawn until it is decided, or
  until a moment the case type names. After that the action is gone and the
  reason is on screen.
- **The applicant's own case, and nobody else's.** The action is bound to the
  identity that may act on this case, and to a mandate where one grants it.
- **A confirmation with a reason.** Withdrawal is not a save. The citizen
  confirms, and may say why.
- **The target status comes from the case app.** The client sends no status.
  A tampered request still lands where the case type said.
- **A withdrawal is its own event.** `portal.withdraw.client` reaches the case
  app with the case, the identity and the reason, so a rule can stop a clock
  or a payment instead of guessing from a status change.

## How dossiq consumes it

dossiq declares on the case type whether a citizen may withdraw, until when,
and which status a withdrawal lands on. It listens for the withdrawal event
and runs the transition through its own guard, so the existing `withdrawn`
status stays the one place the state lives. Nothing about this is rendered by
dossiq.

No dossiq change on `development` carries that half today. It is to be
specified in dossiq. The two nearest changes are `what-a-transition-declares`,
which is where a transition's declaration belongs, and `citizen-status-labels`,
which supplies the public label the citizen reads after the withdrawal. Neither
mentions the citizen withdrawing anything.

## Existing specs it extends

`what-the-citizen-may-write-on-their-own-case` (this repo), which opened the
citizen's write surface and declared the writable set. This change adds the one
act that is not a field write. It also extends `portal-status-transitions` for
the server-fixed target and `portal-contribution-contract` for the declaration
and the event.

## ADRs

- ADR-046: the case app declares, portaliq serves. The withdrawal window and
  its target status are declarations, read by the portal and kept nowhere else.
- ADR-041: the withdrawal reaches the case app as a typed event, never as an
  HTTP call into another app.
- ADR-023 (portaliq): withdrawing is an action, so it is authorized as an
  action, not inferred from the ability to read the case.
- ADR-082: the action is throttled per identity and per case.

## Size

M. A declaration to read, one action, an event, and the guards around them.

## Out of scope

- The `withdrawn` status itself, and what it does to a term or an invoice.
  dossiq owns the status vocabulary and the rules behind it.
- A handler withdrawing a case internally. That path exists and stays where it
  is.
- Deleting a case. A withdrawal is a state, never a removal.
