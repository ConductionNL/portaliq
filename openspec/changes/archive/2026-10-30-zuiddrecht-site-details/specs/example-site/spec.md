## ADDED Requirements

### Requirement: The Zuiddrecht example site names its organisation and fictitious contact details
The shipped Zuiddrecht site MUST declare `organisation: zuiddrecht`, the slug of the OpenRegister organisation the Zuiddrecht example set creates and the organisation of the portal accounts it writes. Its texts MUST NOT hold the placeholders `[telefoonnummer]`, `[e-mailadres]` or `[adres]`; contact details MUST be fictitious (an e-mail address on `zuiddrecht.example`, a phone number marked as an example in the footer), except that a text about direct danger MUST name 112.

#### Scenario: The shipped site
@e2e exclude Node: tests/example-site.spec.mjs
- GIVEN `lib/Settings/sites/zuiddrecht.json`
- WHEN it is read
- THEN its portal names organisation `zuiddrecht` and no text holds a bracketed placeholder
