## Why

The guardian overview board (De Wilgenboom, MijnOverzicht) shows "Nieuw van school" as rows: who
an item is for, when, and its title as a link. The school portal proof (06 Oct, item 11) showed
every item's full body instead, with its markdown as raw text (`**`, `[link](...)`), because the
account page's news block rendered each item as a whole article.

## What Changes

- `NewsBlock.vue` (the `news` block of a contributed page) shows one row per item:
  - the audience as a badge, from `audienceLabel` when the item has one;
  - the date in the page language;
  - the title (the translated title when the item carries one) as a link-styled button that
    opens the news screen.
  The body is not shown; it stays on the news screen, which renders it. "All news" moves to the
  heading row, as on the board. Tokens only.
- `tests/site-look/account-news-rows.spec.mjs`.

## Impact

- Only the account pages' news block. The news screen and the public news blocks are unchanged.
