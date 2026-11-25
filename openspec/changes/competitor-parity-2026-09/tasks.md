# Tasks: competitor-parity-2026-09

The build order of the proposal as checkboxes. A box is ticked when the named
change is archived.

- [x] 1 `portal-identity-space`
  Ticked (decision 399): archived as `openspec/changes/archive/2026-09-28-portal-identity-space`.
- [x] 2 `change-proposal-queue`
  Ticked (decision 399): archived as `openspec/changes/archive/2026-09-29-change-proposal-queue`.
- [x] 3 After `portal-task-delivery` and `portal-identity-space`:
  `partner-tasks-in-the-portal`
  Ticked: archived as `openspec/changes/archive/2026-09-28-partner-tasks-in-the-portal`.
- [x] 4 After `portal-shared-runtime` and `portal-headless-content-api`:
  `embedded-intake-form`
  Ticked: archived as `openspec/changes/archive/2026-11-16-embedded-intake-form`.
- [ ] 5 Hand the dossiq halves named in the index to the dossiq lane, with the
  register row ids.
- [ ] 6 Ask the register to re-rate 6.7 against the shipped portal inbox, and
  to record 1.1 as carried by dossiq and buildiq.
- [ ] 7 Add any portaliq change opened from the register after this umbrella to
  the index, in the same PR.

## Wave 2, the round 4 discovery clusters

- [x] 8 `portal-identity-and-the-organisations-cases` (cluster 7, D8)
  Ticked (decision 399): archived as `openspec/changes/archive/2026-09-28-portal-identity-and-the-organisations-cases`.
- [x] 9 After `portal-identity-and-the-organisations-cases`:
  `portal-intake-form-as-an-object` (cluster 51, D16)
  Ticked: archived as `openspec/changes/archive/2026-09-28-portal-intake-form-as-an-object`.
- [x] 10 After `portal-intake-form-as-an-object`:
  `what-the-citizen-may-write-on-their-own-case` (cluster 48, D16)
  Ticked: archived as `openspec/changes/archive/2026-09-28-what-the-citizen-may-write-on-their-own-case`.
- [ ] 11 Hand the dossiq halves of the three wave 2 clusters to the dossiq
  lane, with the candidate ids.
- [ ] 12 Ask the discovery plan to record `forms-per-case-type` as buildiq's,
  not dossiq's.


## Wave 3, the pending proposals from the D1 batch

- [x] 13 `withdrawing-your-own-case-from-the-portal` (row 2.47)
  Ticked (decision 399): archived as `openspec/changes/archive/2026-09-28-withdrawing-your-own-case-from-the-portal`.
- [x] 14 After `portal-identity-and-the-organisations-cases`:
  `portal-visibility-follows-the-party-tree` (row 13.38)
  Ticked: archived as `openspec/changes/archive/2026-09-28-portal-visibility-follows-the-party-tree`.
- [x] 15 After the two above:
  `a-report-without-an-account-and-a-custodian-who-may-reveal-it` (row 13.33)
  Ticked: archived as `openspec/changes/archive/2026-09-28-a-report-without-an-account-and-a-custodian-who-may-reveal-it`.
- [ ] 16 Hand the three dossiq halves of wave 3 to the dossiq lane, with the
  row ids. None of them exists on dossiq `development` today.

## Buildiq consumer halves (handed over 25 Nov 2026 by the buildiq lane)

- [ ] 17 What `buildiq-registration-form` and `buildiq-page-layout` serve to
  the portal; leaf shapes and example answers in `buildiq-consumer-halves.md`.
  - [x] 17.1 `isPublic` arrives on each served form: only a form with
    `isPublic: true` opens for a visitor without a session; cluster 51 keeps
    the captcha, the address check and the reuse of earlier case data as
    they are (buildiq `forms-per-case-type` 8.4, `competitor-parity-2026-09` 2.4).
    Built 25 Nov 2026: `PortalFormBindingResolver::render()` carries the
    served form's `isPublic` (a boolean, else null) and `PortalFormTrustLevel`
    asks for any session (`low`) when it is `false`, so the page, the embed
    and the submit (`FormSignInGate`, `PortalEmbedController`) all refuse an
    anonymous visitor. A form that does not say keeps today's levels; the
    captcha, address check and earlier-case reuse are untouched. Tests:
    `PortalFormTrustLevelTest::testAServedFormThatIsNotPublicNeedsASession`,
    `PortalFormBindingResolverTest::testAServedFormThatIsNotPublicAsksForASession`,
    `::testAFormThatDoesNotSayStaysAsBefore`.
  - [x] 17.2 `confirmationText` arrives on each served form and replaces the
    intake confirmation for that form. Already consumed:
    `PortalFormBindingResolver::withFormConfirmation()`.
  - [ ] 17.3 A portal page that shows a case asks `buildiq-page-layout` as the
    visitor: buildiq resolves a caller without an account to the `portal` and
    `everyone` audiences only, never a group, team or user layer, so the
    portal sends no audience of its own (buildiq
    `screen-overrides-as-a-patch-with-fall-through` 5.2,
    `competitor-parity-2026-09` 5.3).
  - [ ] 17.4 The served form `fee` (`{amount, currency, description}`, buildiq
    `forms-fee-on-submit` T05) as `portalFee` when the case type declares
    none. Waits for a decision (money): the served fee has no `payAction`,
    and the pay route (D3 of `intake-pay-on-submit`) needs one.
  - [x] 17.5 Count the use of hosted forms too: `IntakeFormBlock`'s form now
    carries `data-portaliq-form` (the served form id, else the binding route),
    so `portal-traffic-outcomes` counts starts, submits, abandons, the
    completion rate and the field people leave on for buildiq's registration
    forms (buildiq `capability-requirements-backfill` T20, REQ-BQCV-020).
    Before, the form had no id, name or action and the traffic client skipped
    it. Test: `tests/intake-form-traffic-id.spec.mjs`.
