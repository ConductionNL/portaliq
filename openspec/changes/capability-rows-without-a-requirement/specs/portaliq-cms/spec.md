## ADDED Requirements

### Requirement: A portal's minimum trust gates its content reads (REQ-CRG-004)

When a portal declares `authentication.minTrust` above `low`, every content read for that portal that is not marked public SHALL compare the session's trust with it before it reads a page, and SHALL refuse a caller without a session or with a lower trust. The refusal SHALL carry no page body.

#### Scenario: An anonymous caller on a DigiD portal
- **GIVEN** a portal with `minTrust: substantial` and a published page `/mijn-regelingen`
- **WHEN** a caller without a session requests that page through the content API
- **THEN** the answer carries no page body and says the trust is insufficient

#### Scenario: A resident signed in at substantial
- **WHEN** a resident whose session trust is `substantial` requests the same page
- **THEN** the page is returned

### Requirement: The glossary and the contributed services are blocks an editor places (REQ-CRG-006)

The widget grid SHALL offer a `glossary` block and a `contributions` block that an editor places on any page. The host SHALL fetch the portal's own glossary terms, sorted alphabetically, for the glossary block, and the anonymous contributions aggregate for the contributions block. No route SHALL show either section unless a page places its block. A contributing app that fails SHALL leave the other entries of the contributions block in place.

#### Scenario: An editor adds the glossary to a page
- **GIVEN** a portal with the terms "Bestemmingsplan" and "Aanslag"
- **WHEN** an editor places the glossary block on the page `/begrippen` and publishes it
- **THEN** a visitor of `/begrippen` sees "Aanslag" before "Bestemmingsplan", and a page without the block shows no glossary

#### Scenario: The home page lists the services
- **GIVEN** two apps that contribute public services, one of which throws
- **WHEN** a visitor opens a home page that places the contributions block
- **THEN** the block lists the services of the app that answered

### Requirement: Header, footer and legal menus follow one position field (REQ-CRG-007)

A portal's menu with `position` 0 SHALL render in the header. Every other menu SHALL render as a footer column, in position order. When the portal has two or more footer menus, the one with the highest position, at 2 or above, SHALL render as the legal strip under the footer instead. A portal with one footer menu SHALL keep it as a column. Each menu SHALL show one level of children, in their order.

#### Scenario: Three footer menus
- **GIVEN** menus at positions 0, 1, 2 and 3
- **WHEN** a page renders
- **THEN** the header shows menu 0, the footer shows menus 1 and 2 as columns, and menu 3 is the legal strip

#### Scenario: One footer menu
- **GIVEN** menus at positions 0 and 2
- **WHEN** a page renders
- **THEN** menu 2 is a footer column and there is no legal strip

### Requirement: The site fits a phone without scrolling sideways (REQ-CRG-008)

At a phone width of 360 pixels the site SHALL NOT scroll horizontally on any page, and the widgets of a grid row SHALL stack in one column instead of narrowing side by side.

#### Scenario: The home page on a phone
- **GIVEN** a home page with three widgets in one grid row
- **WHEN** a visitor opens it at 360 pixels wide
- **THEN** the page is no wider than the screen and the three widgets are stacked
