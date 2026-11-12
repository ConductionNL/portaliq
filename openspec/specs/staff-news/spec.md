# staff-news Specification

## Purpose
How staff read and edit a news item in the admin app: the dialog says which list the item belongs to and what state it is in, so an editor knows what they are changing.

## Requirements

### Requirement: The news dialog names the list and the item's state above its title

The news dialog SHALL show a line above its title: "News" for a new item, "News, draft" for an item in draft and
"News, published" for a published item.

#### Scenario: A clerk changes a draft

- GIVEN a news item in draft
- WHEN the clerk chooses Change in its row menu
- THEN the dialog reads "Nieuws, concept" above "Nieuwsbericht wijzigen"
