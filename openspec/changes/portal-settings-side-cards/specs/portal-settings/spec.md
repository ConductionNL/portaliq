# portal-settings Delta: portal-settings-side-cards

## ADDED Requirements

### Requirement: An administrator switches the Berichtenbox offer from the portal's settings

The settings tab of a portal SHALL show a "Berichtenbox van MijnOverheid" side card with a switch that reads and writes
whether the portal's organisation offers the message box channel (`messageBox` in its presentation settings), through
`GET` and `PUT /api/portals/{slug}/message-box` (administrators only). Switching off SHALL move the configured
`{sourceId, label}` aside (`messageBoxOff`) and remove `messageBox`; switching on SHALL restore it. When no source was
ever configured the endpoint SHALL refuse to switch on (422 `no_source`) and the card SHALL disable the switch and say
the digital post source must be set up in integriq first. The endpoint SHALL never take or return a recipient.

#### Scenario: switching the offer off and on again

- **GIVEN** an organisation offering the message box with source `mijnoverheid` and label "Berichtenbox"
- **WHEN** the administrator switches it off and then on
- **THEN** residents are offered no message box while it is off, and the same source and label are offered again after

#### Scenario: no source configured

- **GIVEN** an organisation that never configured a message box source
- **THEN** the card's switch is off and disabled, and a PUT to switch it on answers 422 `no_source`

### Requirement: An administrator switches whether a portal can be installed

A portal SHALL carry `installable` (boolean, default true). The settings tab SHALL show an "Als app installeren" side
card with a switch on that field. While it is false the web app manifest route SHALL answer 404 for that portal, so a
browser offers no install; the service worker keeps serving the site shell.

#### Scenario: switching install off

- **GIVEN** a portal with `installable` false
- **WHEN** a browser asks for its web app manifest
- **THEN** the answer is 404
