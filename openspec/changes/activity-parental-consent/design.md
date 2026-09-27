# Design: activity-parental-consent

## Architecture Overview

Builds on `extracurricular-activity-offer`. No new class, no new route.

```
guardian signup (acceptedStatement) --> ActivitySignupService::checkedSignup  --> 422 consent_required
                                    --> ActivitySignupService::writeSignup     --> consent record
staff open                          --> ActivityController::open               --> 422 no_consent_statement
staff roster (ActivityRoster)        --> ActivityFeedReader::photoConsentGranted
guardian feed                        --> ActivityFeedReader::photoConsentGranted --> GuardianAudienceFixtureReader::photoConsentGranted (first call site)
```

## API Design

`POST /apps/portaliq/api/activities/{id}/signup` gains `acceptedStatement`
(string). `PUT /api/activities/{id}/open` gains 422 `no_consent_statement`.
`POST /api/activities` accepts `consentRequired`, `consentStatement` and
`photosTaken`. Roster and feed entries gain `consent` / `consentGrantedAt` and,
where photos are taken, `photoConsent`.

## Database Changes

None: new optional properties on two OpenRegister schemas.

## Decisions

### D1: The guardian sends back the text, not a checkbox

A boolean says "yes" to whatever text is current when the request lands. If
staff reword the permission slip between the page load and the click, a
boolean would record agreement to text the guardian never saw. Sending the text
and comparing it closes that gap, and it keeps a boolean flag out of the
service signature.

### D2: The record stores the text, not a reference

`consent.statement` is a copy. The activity's text can change; what one
guardian agreed to on one day cannot.

### D3: Photo consent is read live, through the existing helper

`photoConsentGranted(subjectRef, childRef, purpose)` already exists and fails
closed. The roster reads it at request time, so a guardian who withdraws photo
consent shows as withdrawn on the next roster read, with no copy to go stale.
Purpose `news` is used because photos from a school activity are shared with
parents through the same channel as news items; a separate purpose can be added
when learniq's `beeldmateriaalConsent` purposes replace the fixture.

### D4: Withdrawing consent is withdrawing the sign-up

A child without permission cannot take part, so there is no separate
"consent withdrawn but still signed up" state.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Why |
|---|---|---|
| Consent check on sign-up | imperative, in `ActivitySignupService` | A guard on a write the service already decides (lifecycle guard exception) |
| Consent statement required to open | imperative, in `ActivityController::open` | Same guard as the places check |
| Photo consent on the roster | imperative, via `ActivityFeedReader` | Reads another schema's data per row at request time; not a stored derived field |

## Nextcloud Integration

No new OCP use. Reuses `GuardianAudienceFixtureReader`, `ITimeFactory`.

## Security Considerations

- Consent can only be given by a guardian for their own child: the existing
  own-child check runs before the consent check.
- The consent record is written by the server from the activity's own text and
  the bearer's subject; the client cannot set `grantedByRef` or `grantedAt`.
- `activitySignup` stays read-restricted to admins in OpenRegister.

## Mixed-spec rationale

Three optional schema properties and the code that reads them; neither half is
useful alone.

## File Structure

```
lib/Settings/portaliq_register.json          activityOffer 0.2.0, activitySignup 0.2.0, register 0.35.0, seed
lib/Settings/portaliq_mock_register.json     consent fields on demo objects
l10n/                                         new schema strings
lib/Service/ActivitySignupService.php         consent check and record
lib/Service/ActivityRoster.php                the staff roster with photo consent (moved out of ActivitySignupService to keep it under the class complexity budget)
lib/Service/ActivityFeedReader.php            photoConsentGranted delegate, mySignups annotation
lib/Service/ActivityDraft.php                 consent fields on a new activity
lib/Controller/ActivityController.php         open refuses consent without a text
lib/Controller/ActivityGuardianController.php passes acceptedStatement
tests/Unit/...                                 the matching tests
docs/operations/term-long-activities.md       consent section
```

## Seed Data

### Schema: `activityOffer` (changed)

| Field | `activity-schaakclub-najaar` | `activity-schoolzwemmen-groep-5` |
|---|---|---|
| consentRequired | false | true |
| consentStatement | none | Mijn kind mag met de bus mee naar het Sportfondsenbad en daar onder begeleiding zwemles volgen. |
| photosTaken | false | true |

### Schema: `activitySignup` (changed)

| Field | `signup-schaakclub-lars` | `signup-schaakclub-tim` |
|---|---|---|
| consent | none (not required) | none (not required) |

The mock register's `activitysignup-zwemmen-lars` gains a consent record with
the schoolzwemmen text, `grantedByRef: guardian-anna-devries`.

## Trade-offs

- Sending the full text back is more bytes than a boolean; it is one short
  paragraph and buys a record of exactly what was agreed.
