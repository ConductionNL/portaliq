---
kind: code
depends_on: [portal-identity-and-the-organisations-cases]
---

# Proposal: portal-visibility-follows-the-party-tree

## The row this closes

**13.38**, area Access and privacy, rated `no` for dossiq: "Portal visibility
derived from the party tree, so a parent sees its subsidiaries."

Source field, verbatim: `dossiq#2314, published as 13.31`.

Corpus batch file, `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
in ConductionNL/market-intelligence, the table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **13.38** | 13.31 | Portal visibility derived from the party tree, so a parent sees its subsidiaries | no | unread | discovery D-request-tracker-26 |
```

The ledger note, verbatim:

> Row 13.13 grants portal rights per case. A holding company with eleven subsidiaries is then eleven separate grants, maintained by hand as the group changes.

## What the competitor evidence is

There is none in the matrix, and the corpus says so in as many words. The row
is one of the 98 promoted under decision D1, and the batch file's own preamble
reads:

> **Every competitor column is `unread`, and none of them is `no`.** The corpus columns are GLPI, Zammad, OpenProject, Plane, Redmine, Forgejo, osTicket, FreeScout, Znuny, GitLab, OTOBO, iTop, Odoo, Deck, Kanboard, Vikunja, RT, Helpdesk, Gitea, Taiga, Tuleap, Huly, JSM, YouTrack, Jira DC, Easy Redmine, OpenCase, GZAC, xxllnc Zaken and Dimpact ZAC.
> Not one of them has been read against a row below. `unread` is what the ledger writes for that, and the distinction is the whole point: `no` is a reading of a product somebody opened, and filling these cells with it would fabricate thirty readings per row.

The cross-reference points at the round 4 discovery sweep, and that is the one
place with a reading. `D-request-tracker-26` is flat group membership, and the
sweep is explicit that it is not this row
(`procest/_round4/discovery/request-tracker.md`, verbatim):

> **D-request-tracker-26**, the portal showing an organisation's cases, sits beside 13.38 pending (dossiq-only) *Portal visibility derived from the party tree, so a parent sees its subsidiaries*. 13.38 is a tree of legal entities; RT's and Frappe's is flat group membership, which is what eHerkenning ketenmachtiging actually produces. Keep it, worded as flat membership, and fold it into 13.38 if Ruben would rather have one row.

The dedup file says the same in one line
(`procest/_round4/discovery/candidates.md`, verbatim):

> All three hang portal visibility on organisation membership; adjacent to the party tree row, which is hierarchical.

## Why the row is open

The flat half is built. `portal-identity-and-the-organisations-cases` carries
REQ-PIOC-002, "A portal user sees the cases of the organisation they are
mandated for", and its design says outright what it does not do:

> ## D2. The organisation view is a scope, not a second tree

and

> The default for an organisation with no mandate recorded is nothing, not everything.

That is correct and it leaves the row open. A holding company with eleven
subsidiaries needs eleven mandates recorded, and somebody has to maintain them
as the group changes. That is the ledger note, word for word, and it is the
work this change removes.

## What portaliq builds

- **A mandate that may reach down the tree.** A mandate says whether it covers
  the organisation named, or that organisation and the entities below it. The
  default stays the organisation named.
- **A scope resolved by walking the tree openregister owns.** The portal reads
  the party relations. It copies no hierarchy and keeps no second tree.
- **A bound on the walk.** A depth, a page size and a refusal when the tree is
  larger than the bound, rather than an unbounded read that works in a test and
  times out on a real group.
- **The view says which entity and which mandate.** A case belonging to a
  subsidiary names the subsidiary and names the mandate on the parent that
  grants it.
- **The group changes, and so does the view.** A subsidiary sold today stops
  being visible without anyone revoking a grant. A subsidiary acquired today
  becomes visible without anyone writing one.
- **Cases stay where they were filed.** Visibility follows the tree. Ownership
  does not move, and a parent reading a subsidiary's case is a read, not a
  transfer.

## How dossiq consumes it

dossiq changes nothing about how it files a case. Its provider keeps scoping
by the requester claim, and the claim portaliq supplies now resolves through
the tree rather than to one organisation. dossiq declares, per case type,
whether a case may be seen through a parent at all, because a case about a
subsidiary's staff is not a case the holding may read.

No dossiq change on `development` carries that declaration today. It is to be
specified in dossiq. The nearest is `case-grants-name-their-source`, which says
where a grant came from; it names no tree.

## Existing specs it extends

`portal-identity-and-the-organisations-cases` (this repo), REQ-PIOC-002 and
REQ-PIOC-008. This change makes a mandate able to reach further than the
organisation it names, and makes the switcher offer an entity below the one
the mandate named. It also extends `portal-contribution-contract` for the
scoping and `supplier-portal` for the organisation record.

## ADRs

- ADR-046: the case app declares whether its cases may be read through a
  parent. portaliq resolves the scope and renders it.
- ADR-099: the mandate is a granted, run-scoped identity claim. A parent reads
  a subsidiary's case under a named mandate, recorded on every write.
- ADR-058: walking a hierarchy is a query that grows with the group, so it is
  bounded. An unbounded read of the party relations is the footgun this ADR
  exists for.
- ADR-023 (portaliq): reaching down the tree is an action-level grant on the
  mandate, not a side effect of being able to read the parent.

## Size

M. A flag on the mandate, a bounded walk over relations openregister already
holds, and the scope and the labels that follow from it.

## Out of scope

- The party model itself. openregister owns parties and their relations, and
  this change reads them.
- A relationship that grants dated access between two parties. That is ledger
  row 13.34, and it is a different mechanism with a start and an end date.
- Writing on a subsidiary's case from the parent. This change grants
  visibility. What may be written stays with the writable set.
