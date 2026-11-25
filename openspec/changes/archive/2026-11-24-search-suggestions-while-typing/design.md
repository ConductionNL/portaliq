# Design: search-suggestions-while-typing

## Screen

Follows the Zuiddrecht board **Zoeken** ("Website: Woo-publicaties zoeken", canvas `5NkFW28vZUUij43xzxHg5a`). The input carries `role="combobox"`, `aria-expanded`, `aria-controls="woo-suggesties"` and `aria-autocomplete="list"`. Under it a listbox of rows 48px high: the typed part in bold, the rest of the title in normal weight, the kind as a tag on the right. The first row is highlighted.

The same list serves the header search box (`portal.headerSearch`), which submits to the search page.

## Query

On input of three or more characters, after 250 ms without typing, the block requests the federation endpoint it already uses (`/index.php/apps/opencatalogi/api/federation/publications`) with the typed text, `_limit=5` and only the title and kind fields. A newer keystroke aborts the older request (`AbortController`). A failed or slow request (over 1 s) shows no list and no error: the search button still works.

ADR-022: portaliq adds no suggestion endpoint and no index of its own. The suggestions honour the same publication visibility as the search.

## Keyboard and screen reader

Arrow down and up move the active option (`aria-activedescendant`), Enter opens the active publication, Escape closes the list and keeps the text, Tab leaves the box and closes the list. The existing live region (`srch-a11y-live-region`) announces "{n} suggesties" when the list opens.
