---
status: proposed
---

# Spec: portal-contribution-contract (create-body cross references)

## ADDED Requirements

### Requirement: An action MUST be able to declare which of its fields are references

A `type: create` or `type: update` action SHALL be able to declare
`crossRefs`: a map from a whitelisted field name to the `register`, `schema`
and `scopeField` the value in that field must resolve inside, with an
optional `required` flag and an optional `scopeClaim`. A declaration that is
malformed, that names a field the action does not whitelist, or that omits
any of the three required keys SHALL remove the ACTION from the manifest
rather than only the declaration.

#### Scenario: A sound declaration survives normalisation
@e2e exclude {a manifest shape, asserted in tests/Unit/Contribution/CrossRefConfigNormaliserTest.php::testASoundDeclarationIsKept}

- **GIVEN** a create action whitelisting `tegenZaakId` and declaring it as a
  reference to `dossiq/case` scoped by `portalSubject`
- **WHEN** the manifest is normalised
- **THEN** the action SHALL keep its declaration with `required` resolved

#### Scenario: A guard that could not be read takes its action with it
@e2e exclude {a manifest shape, asserted in tests/Unit/Contribution/CrossRefConfigNormaliserTest.php::testAMalformedDeclarationDropsTheAction}

- **GIVEN** a create action declaring a reference with no `schema`
- **WHEN** the manifest is normalised
- **THEN** the action SHALL be absent from the manifest

#### Scenario: A guarded action is never anonymous
@e2e exclude {a manifest shape, asserted in tests/Unit/Contribution/CrossRefConfigNormaliserTest.php::testAGuardedActionLosesItsAnonymousFlag}

- **GIVEN** a create action declaring both `crossRefs` and `anonymous: true`
- **WHEN** the manifest is normalised
- **THEN** the action SHALL keep its references and lose `anonymous`

### Requirement: A declared cross reference must resolve inside the subject's own scope

Before a create or an update reaches storage, Portaliq SHALL resolve every
declared reference in the write body through the subject-scoped read. A
reference that does not resolve SHALL refuse the whole write with HTTP 403,
`error: cross_ref_refused` and the field that failed. A declared reference
the client left out SHALL refuse only when it is `required`.

#### Scenario: A citizen names somebody else's case
@e2e exclude {needs two citizen sessions against a live portal; asserted in tests/Unit/Service/PortalCrossRefGuardTest.php::testAReferenceOutsideTheSubjectsScopeRefuses}

- **GIVEN** a create action declaring `tegenZaakId` as a reference to the
  citizen's own cases
- **WHEN** the body names a case that is not theirs
- **THEN** the write SHALL be refused and nothing SHALL be stored

#### Scenario: A citizen names their own case

- **GIVEN** that same action
- **WHEN** the body names a case the citizen may already read
- **THEN** the write SHALL proceed
- @e2e exclude {the happy path of the above; asserted in tests/Unit/Service/PortalCrossRefGuardTest.php::testAReferenceInsideTheSubjectsScopePasses}

#### Scenario: A required reference that was left out

- **GIVEN** an action declaring `tegenZaakId` as required
- **WHEN** the body omits it
- **THEN** the write SHALL be refused naming that field
- @e2e exclude {asserted in tests/Unit/Service/PortalCrossRefGuardTest.php::testARequiredReferenceThatIsAbsentRefuses}
