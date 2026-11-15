## ADDED Requirements

### Requirement: Staff can replay a masked recording (REQ-CRG-011)

The Traffic page SHALL list a portal's recordings and SHALL replay one in a player, from the stored chunks only: clicks, scrolling and page changes, with text shown by its length and typed values never shown. Only staff who may read the portal's traffic SHALL open the player.

#### Scenario: An editor watches where a visitor got stuck
- **GIVEN** a portal with recording switched on and one recorded visit that typed in a search field
- **WHEN** an editor opens the recording from the Traffic page
- **THEN** the player replays the clicks and scrolling of that visit, and the search field shows no typed text

### Requirement: The client honours Do Not Track (REQ-CRG-013)

When the browser sends Do Not Track (`navigator.doNotTrack` is `"1"`), the served client SHALL send no event and SHALL write nothing to the browser, whatever consent the portal asks for.

#### Scenario: A visitor with Do Not Track on
- **GIVEN** a measuring portal and a browser with Do Not Track switched on
- **WHEN** the visitor opens three pages
- **THEN** the collector receives no event from that browser and no key is written to its storage
