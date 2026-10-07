# Spec delta: portal-in-place-editing

## ADDED Requirements

### Requirement: The site MUST show what an editor published, not a cached copy (REQ-SSP-001)

After an editor publishes a page in place, or leaves edit mode, the site SHALL
read the page from the server past the browser cache and show that version.
The content API SHALL NOT answer a signed-in Nextcloud user with a publicly
cacheable response, and SHALL keep answering an anonymous visitor with one.

#### Scenario: An editor publishes and leaves edit mode
- **GIVEN** an editor on the published page `/over-ons`, which their browser read before
- **WHEN** the editor changes it in place, publishes and leaves edit mode
- **THEN** the site shows the published version at once, not the copy the browser cached
- test: `tests/site-edit-mode.spec.mjs` ("a fresh page read goes past the browser cache, an ordinary one does not", "the editor tells the site it published, and the site re-reads the page fresh")

#### Scenario: A signed-in reader gets no cacheable page
- **GIVEN** a signed-in Nextcloud user and no resident bearer
- **WHEN** they read a page from `/api/content/page`
- **THEN** the answer is `Cache-Control: private, no-store`
- test: PHPUnit `tests/Unit/Controller/ContentControllerTest.php` ("testAPageReadBySignedInNextcloudUserIsNeverCached")

#### Scenario: An anonymous visitor's page stays cacheable
- **GIVEN** no Nextcloud session and no resident bearer
- **WHEN** a visitor reads a page from `/api/content/page`
- **THEN** the answer is `Cache-Control: public, max-age=300, must-revalidate`
- test: PHPUnit `tests/Unit/Controller/ContentControllerTest.php` ("testAPageReadByAnonymousVisitorStaysCacheable")
