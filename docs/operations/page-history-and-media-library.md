---
title: Page history and the media library
sidebar_label: History and media
description: How an editor goes back to an earlier version of a page, and keeps images and files in one library per portal
---

# Page history and the media library

An editor can go back to what a page said before, and can keep images and files in one library per portal to use on any page.

## Going back to an earlier version

In the page designer (Pages, then a page, then Edit layout), **History** lists the published versions of the page, newest first, with who published each one and when.

**Restore this version** puts that version in the page's draft. The live page does not change. Check the draft in the designer, then choose **Publish**. Nothing goes live on its own.

A version is every moment the page's content was published. Saving a draft does not make a version. A version recorded without its content (for example one that only switched the page to published) is listed without a restore button.

Only page editors see the history: the administrators and the editor groups set in the Portaliq admin settings. The history is read from OpenRegister's record of changes to the page, so no second copy of your pages is kept.

## The media library

**Media** in the app's menu lists the portal's library. For each item:

1. Choose **Add**, give it a title, pick the portal, and choose **Image** or **File**. An image needs alternative text: what the image shows, read aloud to someone who cannot see it. Portaliq refuses to save an image without it.
2. Open the item and upload its file in the sidebar's **Files** tab.
3. Set the status to **Published**. Only a published item is shown to the public.

### Using an item on a page

In the page designer, **Media** lists the published items of the page's portal:

- **Use as hero image** shows the image at the top of the page, with its alternative text.
- **Use as share image** is the image shown when the page is shared.
- **Copy for a text** copies a reference to paste into a text widget, such as `![Het stadhuis aan de Markt](media:<id>)`.

The hero image and the share image are page fields, not part of the draft: the next save of the page, draft or publish, puts them on the live page.

A page keeps a reference to the item, not a copy. To change an image on every page that uses it, upload a new file on the item: the newest file is the one shown.

### Deleting an item

An item that a published page uses cannot be deleted. The refusal names the pages, for example "This item is used on published pages: /about, /contact. Remove it there first."

### Where the public reach an item

The public reach a published item at `/apps/portaliq/api/content/media/<id>` on the portal's own address. A draft item, another portal's item and an unknown id all answer "not found". A portal that requires signing in to read its content does not serve library items yet.
