## ADDED Requirements

### Requirement: A catalogue may filter by kind and by audience

The catalogue query MUST accept a label for a facet by kind and a label for a facet by audience. With
a kind label every item MUST carry that facet with its kind, a news item with the word the block
gives for news. With an audience label every news item that names its audience MUST carry that facet
with it. A facet label an item already declares MUST stay as declared. Without labels nothing is
added.

#### Scenario: "Soort" and "Voor wie" on De Wilgenboom's news
@e2e exclude PHPUnit: PublicCatalogueTest; live check on :8092 in the PR
- GIVEN two public news items for "hele school" and "groep 7"
- WHEN the block asks for `{"kind": "Soort", "news": "Nieuws", "audience": "Voor wie"}`
- THEN the answer's facets hold "Soort" with "Nieuws (2)" and "Voor wie" with "hele school" and "groep 7"
- AND choosing "Soort: Nieuws" leaves only the news
