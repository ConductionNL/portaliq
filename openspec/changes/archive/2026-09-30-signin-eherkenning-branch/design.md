# Design: signin-eherkenning-branch

Read at portaliq `development` `eeda3fa`.

## Where it sits today

- `lib/Service/OidcClaimMapperService.php`: presets `digid`, `eherkenning`
  (:82-88, audience `supplier`, `loaClaim` `acr`), `eidas`, `generic`;
  `claimMap` per organisation overrides `identityRef`, `subjectRef` and
  `audience` (:140-160). No other claim is mapped.
- `lib/Service/PortalSessionService.php`: `issueSession()` (:259) signs
  `subjectRef`, `audience`, `organisation`, `trust`; `resolveFromBearer()`
  (:403) returns them (:441-443); `refreshSession()` (:470) re-issues them.
- `lib/Service/PortalObjectReader.php`: `readCollection()` (:161) and
  `readObject()` (:324) scope every read by the collection's `scopeField`
  against the subject, server side.
- `lib/Contribution/CollectionConfigNormaliser.php` normalises a collection's
  config fail closed.

## D1. The branch is a claim, mapped like the others

`claimMap` gains `branch`: the name of the claim that carries the
vestigingsnummer. The eHerkenning preset leaves it unset, because brokers name
it differently; an organisation sets it with the rest of its `claimMap`. A value
is accepted only when it is 12 digits (a vestigingsnummer); anything else is
dropped and logged, never widened into a guess.

`issueSession()` takes an optional `branch` and a `branchRestricted` flag and
signs both; `resolveFromBearer()` and `refreshSession()` carry them through. A
branch from the login claim is `branchRestricted: true`.

## D2. Restricted means fail closed; chosen means filter

A collection may declare `branchField`, a projected field.
`PortalObjectReader::readCollection()` and `readObject()`:

- session with `branchRestricted: true`: a collection with `branchField` is
  filtered to rows whose field equals the branch; a collection without
  `branchField` answers no rows and a single object answers 404.
- session with a chosen branch (`branchRestricted: false`): a collection with
  `branchField` is filtered; one without it is read as before.
- session without a branch: as today.

Writes stamp `branchField` from the session when it is set, the same way the
writer stamps `scopeField`, so a case filed under a branch lands on it.

### D2 as built (30 Sep 2026)

The rule lives in `lib/Service/Branch/PortalBranchScope.php`, applied after the
subject scope at the reads a resident reaches (`ContributionController::collection()`
and `::object()`, `PortalCaseListReader` for "My cases", the ownership check of
`CitizenCaseController`), not inside `PortalObjectReader`: that reader also
serves many internal reads (sessions, accounts, settings) that have no branch.
It only removes rows the subject scope allowed, so it cannot widen a read. The
create action declares its own `branchField` for the stamp, because a write
matches an action, not a collection. `branchField` on a collection is kept only
when it names a projected field (`PortalBranchScope::normalise()`, called by
`CollectionConfigNormaliser`).

The branch claim travels as `branch` and `branchRestricted` in the signed
bearer (`PortalJwtService::createSession(branch:)`); `GET /portal/api/session`
returns both for the header.

## D3. Choosing a branch

`POST /portal/api/session/branch` with `{ branch }` or `{ branch: null }`
re-issues the bearer with the chosen branch and `branchRestricted: false`. It
is refused for a restricted session and for a branch that is not one of the
company's, checked against the KvK branches `identity-registered-details`
reads for the account's KvK number. The header's "Acting for" list
(`cases-my-cases-page`) gains the branches, by name and address.

As built (30 Sep): the choice has its own controller
(`SessionBranchController`, also `GET /portal/api/session/branches` for the
list) and its own header control (`BranchSwitcher`, "Acting for branch")
beside the mandate switcher, because a mandate and a branch are different
axes: a person can act under a mandate for another company without that
company's branch list. `{ branch: '' }` means the whole company. Without a
readable branch list every branch is refused (fail closed); the whole company
is always allowed. The rotation is `PortalSessionService::rebranchSession()`,
which shares refresh's absolute cap and records one `refresh`.

## Risks

- A broker that sends the branch under an unexpected claim name gives a
  whole-company session. The `claimMap` setting is per organisation and the
  admin docs say where to find the name in the broker's assertion.
- A restricted session sees nothing of an app that has not declared
  `branchField`. That is the intended answer: the login did not grant the whole
  company.

## What it deliberately does not do

- It does not change how the subject reference of a company is derived.
