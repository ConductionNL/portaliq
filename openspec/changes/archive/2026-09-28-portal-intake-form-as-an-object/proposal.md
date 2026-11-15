---
kind: code
depends_on: [embedded-intake-form, portal-identity-and-the-organisations-cases]
---

# Proposal: portal-intake-form-as-an-object

Round 4 discovery sweep, cluster 51 "The intake form as its own object"
(`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Owner portaliq, size M,
decision D16, no blocking dependency in the plan. Candidates:
`C-intake-6`, `C-intake-16`, `C-intake-39`, `C-intake-41`, `C-intake-43`,
`C-intake-46` (`candidates.json`, lane lines `intake.tsv:31`, `:50`,
`:32`, `:25`, `:34`, `:51`).

## Summary

A citizen opens the portal, finds the request they need, and fills in the
form that belongs to it. The form is not the record type. It is an object
with its own fields, its own order and its own channel, published for the
case type by the form builder. Portaliq renders it, validates it, and
hands the submission to the case app.

## Why

The plan's mechanism line: "extend portaliq `embedded-intake-form`
(portaliq#539)". The form definition itself is not portaliq's. Q1.15 put
the form-as-object on buildiq, and `forms-per-case-type` (buildiq#765) is
on buildiq's `development`: `registrationForm` with `audience`, `name`,
`isDefault` and `presets[]`. Note for the record, the build plan's
mechanism line names that change as dossiq's; it lives in buildiq.

So portaliq owes the other half: the portal rendering, and the citizen
identity that fills the applicant block. Ruben's answer to D16 settles
where each part lives. The portal flag lives on the field. The form owns
order and channel. Neither is a portaliq object, and both decide what
portaliq renders.

## The passers that prove it

Four systems pass a member of the cluster, three driven and one
documented. Proving system: valtimo.

| candidate | relevance | driven | documented | evidence the lane cited |
|---|---|---|---|---|
| `C-intake-16` | must | xxllnc-zaken | | Case type > Webformulier (`case-type-editor-anatomy.md`, `D-xxllnc-72`) |
| `C-intake-6` | should | valtimo | | Case definition Algemeen (`CaseDefinition-Algemeen.md`, `D-valtimo-26`) |
| `C-intake-39` | should | valtimo | | Case create (`CaseCreate.md`, `D-valtimo-8`) |
| `C-intake-46` | should | dimpact-zac | | Inbox productaanvragen (`productaanvraag-intake/spec.md`, `D-dimpact-37`) |
| `C-intake-43` | should | | jira-service-management | help centers, landing pages, topics (`D-jsm-25`) |
| `C-intake-41` | could | xxllnc-zaken | | Case type > Webformulier (`D-xxllnc-15`) |

dossiq rates `partial` on two and `no` on four. The two partials are
thin: `C-intake-16` reads "partial, schema only" and `C-intake-46` reads
"partial, OpenRegister validates on write".

## What portaliq builds

- **A portal form page binds to a published form**, by case type, audience
  and name, and asks the form leaf for it. One case type can carry several
  forms, which is the whole point of Q1.15.
- **Per-form intake settings**: address lookup, prefill from the citizen's
  earlier cases, a challenge, and the confirmation text the citizen reads
  after submitting.
- **An external start form by URL.** A municipality whose forms live in
  Open Formulieren keeps them there. The portal shows the request and
  sends the visitor on, and says where they are going.
- **The applicant block prefilled** from the signed-in portal identity,
  and left empty for a visitor with no session.
- **Validation against the form's schema** before a case is asked for, so
  a refusal reads as a form error, not as a failed write.
- **An asynchronous create** with a receipt the citizen gets at once.
- **The entry point as a composed site**: pages, topics and layouts a
  content editor arranges, listing the published request catalogue and
  starting the right form.

## How dossiq consumes it

Nothing beyond the intake it already declares. A submission from a portal
form arrives through `portal-contribution-contract`, the same path a
submission from an embed takes. dossiq declares its case types and their
forms; it does not render them and does not learn a second submission
shape.

## The catalogue half is opencatalogi's

`C-intake-15`, the public request catalogue, sits in cluster 31 with
opencatalogi as its owner. That change publishes the catalogue. This one
renders it as the citizen's entry point and starts the form behind an
entry. The split follows the ownership rule: the catalogue is published
where publication lives, and the portal is the surface.

## Existing specs it extends

The delta `embedded-intake-form` (portaliq#539) for the anonymous submit
path and the throttle, `portal-contribution-contract` for the create,
`portaliq-cms` and `portal-page-designer` for pages, topics and layouts,
and `portal-identity-space` (portaliq#535) for the identity the applicant
block reads.

## ADRs

- ADR-085: a form is an OpenRegister object with the manifest form
  grammar. Portaliq renders that grammar and invents none of its own.
- ADR-046: the case app declares, portaliq serves.
- ADR-066: the form leaf lists and serves; portaliq asks it.
- ADR-086: the content API is the contract, so a composed entry point is
  content, not a hard-coded page.
- ADR-082: a public form is throttled.

## Out of scope

- Authoring the form. `forms-per-case-type` in buildiq owns that.
- The portal visibility flag on a field. D16 puts it on the field, in the
  record type, which is openregister's and dossiq's ground.
- Publishing the catalogue. Cluster 31, opencatalogi.
- What a citizen may change after submitting. That is the sibling change
  `what-the-citizen-may-write-on-their-own-case`.
