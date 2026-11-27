# portal-notices Delta: portal-notices-as-drawn

## ADDED Requirements

### Requirement: A portal's notices tab lists its notices as a list page does

The Mededelingen tab of a portal's page SHALL list that portal's notices with quick filters and their counts (all, visible now, upcoming, draft), a Filter menu, the view switch, search, selection, six rows a page and a note under the list. Each row SHALL lead with a tile for its level (a warning on the warning tone) and name under the text where the notice shows, in words. A published notice whose window runs SHALL read "Visible now", one whose window has passed "Expired". A row SHALL open the notice's page. The list's Actions menu SHALL stand beside the tabs.

#### Scenario: the notices tab of Gemeente Zuiddrecht
@e2e exclude the list is manifest configuration over nc-vue's index-list; covered by tests/portal-notices-tab-as-a-list.spec.mjs and nc-vue's notice-list-in-a-tab tests
- GIVEN the portal Gemeente Zuiddrecht with fourteen notices
- WHEN Pieter opens its Mededelingen tab
- THEN he reads Alles 14 and the other filters with their counts, and six notices on the first page
- AND the DigiD notice leads with a warning tile and reads "Waarschuwing · toont op Mijn omgeving"

#### Scenario: the header on the notices tab
@e2e exclude covered by tests/portal-notices-tab-as-a-list.spec.mjs
- GIVEN Pieter on the Mededelingen tab
- THEN the header offers Website bekijken and the primary Nieuwe mededeling, and names the portal's pages
