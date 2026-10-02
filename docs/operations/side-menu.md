---
title: A side menu instead of the top menu
sidebar_label: Side menu
description: How to give a portal's pages a menu on the left, so a long menu stops crowding the header
---

# A side menu instead of the top menu

A portal that offers many pages fills its header menu fast. The signed-in pages (`/mijn`) already show the resident's own items in a menu beside the content. The menu block does the same for the portal's other pages: it puts the links in one list on the left of the page, in groups a reader can scan:

- signed in, the resident's own items, in the same groups as the menu on the signed-in pages,
- the site's own pages under the menu's title.

The page marks where the reader is, and on a phone the list folds away behind a "Menu" button.

## Giving every page of a portal the side menu

Add the block to the portal's side region, in the portal record (`regions`):

```json
{
  "regions": {
    "aside": [
      { "id": "site-navigation", "widgetKey": "siteNavigation" }
    ]
  }
}
```

Leave `props` out when it would be empty: the register refuses an empty `{}` there.

Every page of the portal then shows the menu on the left, and the header keeps only the logo, the language and the account controls. The signed-in pages keep their own menu.

## One page only

A page can place the block in its own side region (a widget with `"slot": "aside"`), or leave the side region empty to go without it while the portal has one. A page that places the block in its main grid also hides the header menu, but the block then stays where it was placed.

## Wording

The landmark is called "Menu". A placement may give it another name with the `label` prop. The links themselves always come from the portal's menus and the reader's own sections: the block cannot point anywhere else.
