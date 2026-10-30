# portal-name Delta

## ADDED Requirements

### Requirement: The portal has a name other apps can read

portaliq SHALL keep the name residents know the portal's own area by in its app config under `portalName`, so other
apps can name the portal. An empty value SHALL mean no name is set.

#### Scenario: dossiq names the portal

- **GIVEN** `portalName` is "Mijn Zuiddrecht"
- **THEN** dossiq's outbound message line reads "Ontvangstbevestiging via Mijn Zuiddrecht, ..."

### Requirement: An installed site names the portal when none is set

Installing an example site SHALL set `portalName` to the site portal's `accountLabel` when `portalName` is empty, and
SHALL leave a name already set.

#### Scenario: the Zuiddrecht example

- **GIVEN** no portal name is set
- **WHEN** the Zuiddrecht example site is installed
- **THEN** `portalName` is "Mijn Zuiddrecht"

#### Scenario: an administrator's name stays

- **GIVEN** `portalName` is "Loket Zuiddrecht"
- **WHEN** the example site is installed
- **THEN** `portalName` stays "Loket Zuiddrecht"
