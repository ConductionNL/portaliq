## ADDED Requirements

### Requirement: Publishing a page is the act that makes it public, and the editor is told so

A new page SHALL start as a draft. Publishing SHALL be the only act that makes a page readable at its portal's public address without signing in. Before it publishes, the Publish control in the page designer and in the site edit mode SHALL say that anyone can read the page without signing in, name the address it will be served at, and ask the editor to confirm. The page list SHALL show for each page whether anyone can read it or it is a draft. Taking a published page back to draft SHALL make its route answer exactly as a route that does not exist.

#### Scenario: An editor publishes a page and is told who can read it
- **GIVEN** a draft page `Afval en milieu` at `/afval` in the portal `Zuiddrecht`
- **WHEN** the editor chooses Publish
- **THEN** a confirmation says "Iedereen kan deze pagina lezen, zonder in te loggen" and names the page's public address
- **AND** after the editor confirms, a visitor who is not signed in reads the page at `/afval`
- **AND** the page list shows `Afval en milieu` as public
- @e2e exclude {specified only; task 1.3 adds the e2e}

#### Scenario: An editor who does not confirm leaves the page private
- **GIVEN** the same draft page
- **WHEN** the editor chooses Publish and then cancels the confirmation
- **THEN** the page stays a draft and `/afval` answers as a route that does not exist
- @e2e exclude {specified only; task 1.3 adds the e2e}

#### Scenario: An editor takes a public page back
- **GIVEN** the published page `Afval en milieu`
- **WHEN** the editor takes it back to draft
- **THEN** `/afval` answers with the same status and body as a route that never existed
- **AND** the page list shows it as a draft
- @e2e exclude {specified only; task 1.3 adds the e2e}
