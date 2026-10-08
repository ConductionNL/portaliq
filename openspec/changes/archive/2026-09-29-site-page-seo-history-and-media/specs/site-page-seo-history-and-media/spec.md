---
status: proposed
---

# Spec: site-page-seo-history-and-media

**Status:** proposed
**Scope:** portaliq (owner); OpenRegister supplies the audit trail and object files
**Depends on:** `portal-cms-content-model` (the media schema it proposes), `portal-headless-content-api`, `portal-page-designer`

## Purpose

An editor sets what search engines show for a page, goes back to an earlier
version of a page, and keeps images and files in one library per portal to use
on any page. Requested by the portaliq parity matrix rows `cmp-site-seo`,
`cmp-site-versions` and `cmp-site-media`.

## ADDED Requirements

### Requirement: An editor sets a page's search-engine metadata (REQ-SPH-001)

A page SHALL carry an optional search title, description, a switch to keep it
out of search engines, and a share image. The description SHALL fall back to the
page summary and the search title to the page title.

#### Scenario: An editor writes a description
- **GIVEN** an editor of the portal's pages
- **WHEN** they enter "Opening hours of the town hall" as the description of the page /contact and publish it
- **THEN** the served HTML of /contact carries that text as its meta description

#### Scenario: A page kept out of search engines
- **GIVEN** a published page whose "Keep this page out of search engines" switch is on
- **WHEN** a crawler requests it
- **THEN** the served HTML carries `noindex`

### Requirement: The server renders the head from what the public may read (REQ-SPH-002)

The site SHALL render the page title, description, robots, canonical address and
Open Graph tags in the HTML it serves, without JavaScript. It SHALL read them
through the same anonymous content read as the page, so a draft or a page for
signed-in visitors SHALL NOT put its title or description in the head.

#### Scenario: A crawler without JavaScript reads the title
- **GIVEN** a published public page with the search title "Afval en recycling"
- **WHEN** a client that runs no JavaScript requests it
- **THEN** the `<title>` in the answer is "Afval en recycling" followed by the portal's name

#### Scenario: A draft does not leak
- **GIVEN** a page that exists only as a draft
- **WHEN** its route is requested
- **THEN** the head carries the portal's title and `noindex`, and nothing of the draft

### Requirement: An editor goes back to an earlier version (REQ-SPH-003)

The page editor SHALL list the published versions of a page, newest first, with
who published each and when, read from the page's audit trail. Restoring a
version SHALL put its content in the page's draft and SHALL NOT change the live
page until the editor publishes.

#### Scenario: Restoring last week's text
- **GIVEN** a page published on Monday and changed and published again on Friday
- **WHEN** the editor opens History and chooses "Restore this version" on Monday's entry
- **THEN** the draft holds Monday's content and the public page still shows Friday's until the editor publishes

### Requirement: A portal keeps a media library (REQ-SPH-004)

Editors SHALL be able to upload images and files to a media library of their
portal, with alternative text required for an image, and SHALL be able to pick
an item for a page's hero image, its share image or inside its content. The
public SHALL reach a published item of the portal and no other.

#### Scenario: One image on two pages
- **GIVEN** an editor who uploaded "town-hall.jpg" to the library with alternative text
- **WHEN** they pick it as the hero image of /contact and of /about
- **THEN** both pages show the image, with its alternative text

#### Scenario: A draft item stays private
- **GIVEN** a media item in draft
- **WHEN** a visitor requests `/api/content/media/{id}`
- **THEN** the answer is not found

### Requirement: Replacing an item updates every page, and a used item is not deleted (REQ-SPH-005)

Replacing the file of a media item SHALL keep its id, so every page using it
shows the new file. Deleting an item that a published page references SHALL be
refused, naming the pages.

#### Scenario: A used image cannot be deleted
- **GIVEN** an image used as the hero image of /contact
- **WHEN** an editor deletes it from the library
- **THEN** the delete is refused and the message names /contact
