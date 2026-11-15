---
status: proposed
---

# Spec: portaliq-cms (parental consent on an activity sign-up)

## ADDED Requirements

### Requirement: An activity MUST be able to require a guardian's consent, recorded on the sign-up

`activityOffer` SHALL carry `consentRequired` (boolean) and `consentStatement`
(the text a guardian agrees to). Staff SHALL NOT be able to open an activity
whose `consentRequired` is true and whose `consentStatement` is empty (422
`no_consent_statement`). When `consentRequired` is true, a sign-up SHALL carry
`acceptedStatement` equal to the current `consentStatement`; a missing or
different text SHALL be refused with 422 `consent_required` before anything is
written. An accepted sign-up SHALL store `consent: {statement, grantedByRef,
grantedAt}` with the statement as agreed, the signing guardian and the time.
When `consentRequired` is false, no consent record SHALL be written.

#### Scenario: A sign-up without the consent text is refused
@e2e exclude {an API refusal with no screen in this change; asserted in tests/Unit/Service/ActivitySignupServiceTest.php::testConsentIsRequiredAndRecorded}

- **GIVEN** an open activity with `consentRequired: true` and a consent text
- **WHEN** a guardian signs up their child without `acceptedStatement`, or with an older text
- **THEN** the answer SHALL be 422 `consent_required` and no sign-up SHALL exist

#### Scenario: The agreed text is kept on the sign-up
@e2e exclude {a stored-record assertion; asserted in tests/Unit/Service/ActivitySignupServiceTest.php::testConsentIsRequiredAndRecorded}

- **GIVEN** that activity
- **WHEN** the guardian sends back the exact consent text
- **THEN** the sign-up SHALL store the text, the guardian and the time of agreement

#### Scenario: An activity needing consent does not open without a text
@e2e exclude {staff API refusal; asserted in tests/Unit/Controller/ActivityControllerTest.php::testOpenRefusesConsentWithoutAStatement}

- **GIVEN** a draft with `consentRequired: true` and no `consentStatement`
- **WHEN** staff open it
- **THEN** the answer SHALL be 422 `no_consent_statement`

### Requirement: Where photos are taken, the roster MUST show each child's photo consent

`activityOffer` SHALL carry `photosTaken` (boolean). When it is true, every
entry in the staff roster and in the guardian's own `mySignups` SHALL carry
`photoConsent`, read through `GuardianAudienceFixtureReader::photoConsentGranted()`
for the signing guardian and child (purpose `news`, the channel school photos
are shared through). An absent consent entry SHALL read as false. When
`photosTaken` is false, `photoConsent` SHALL be absent.

#### Scenario: The supervisor sees who may not be photographed
@e2e exclude {needs guardian consent fixtures on a live instance; asserted in tests/Unit/Service/ActivityRosterTest.php::testTheRosterShowsPhotoConsentWherePhotosAreTaken}

- **GIVEN** an activity with `photosTaken: true` and two confirmed children, one with photo consent on file and one without
- **WHEN** staff read the roster
- **THEN** the first SHALL carry `photoConsent: true` and the second `photoConsent: false`
