---
kind: code
---

# Proposal: search-suggestions-while-typing

## Why

A resident types "fietspad Lin" and has to finish the word, press Zoeken and scan a results page to learn whether the decision about the lighting is there. A short list under the box would answer it before she presses anything.

Open Inwoner serves suggestions while you type (`src/open_inwoner/api/urls.py:27`, `AutocompleteView`). Portaliq's search block (`src/site/components/FederatedSearchBlock.vue`) suggests a correction only after a search ("Bedoelde u", search-sort-by-relevance). The Zuiddrecht board **Zoeken** draws three suggestions under the box, each with the typed part, the rest of the title and the kind.

## What changes

- **Suggestions under the box.** After three characters and a short pause, the search block and the header search box show at most five publication titles that match what was typed, with their kind (Woo-besluit, Raadsstuk, Nieuws).
- **Accessible as a combobox.** The box is a combobox with a listbox; arrow keys move, Enter opens the suggestion, Escape closes the list, and a live region says how many suggestions there are.
- **No new index.** Suggestions are a small query on the same OpenCatalogi federation endpoint the block already reads, so a suggestion never names something a search would not find.

## Rows covered

- `srch-autocomplete` (decision 101), screen Zoeken.

## Out of scope

- Suggestions from site pages or the glossary. The block searches publications today; other sources follow the search source they come from.
- Popular searches or search history.
