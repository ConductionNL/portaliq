# Proposal: service-status-page

## Why

Board **OcStatus** draws a site page "Status van de dienstverlening": a table
of the online services with their state, what is going on and since when.
opencatalogi now offers that status in its public index (opencatalogi change
`service-status-on-the-portal`, kind `serviceStatus`), and the Zuiddrecht site
has no page that shows it.

## What changes

- The Zuiddrecht example site gets a `/status` page that needs opencatalogi: an
  introduction, a public table over opencatalogi's `serviceStatus` kind with
  the columns Dienst, Toelichting and Status, and where to go when something
  is not listed.
- The footer menu "Over deze website" links to it.

No code: the page is site configuration over the existing public table block.
