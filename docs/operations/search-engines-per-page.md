---
title: What search engines show for a page
sidebar_label: Search engines
description: How an editor sets a page's search title, description and share image, and how the public site serves them
---

# What search engines show for a page

Every page of a portal can say what search engines and shared links show for it. The public site puts it in the page's HTML itself, so a search engine that runs no JavaScript reads it too.

## The fields

In the page form (Pages, then a page, then Edit):

- **Search title**: the title search engines show. Keep it under 60 characters. Without one, the page title is used. The portal's name is added after it: "Afval en recycling - Gemeente Voorbeeld".
- **Search description**: the text under the title. Keep it under 155 characters. Without one, the page's summary is used.
- **Keep this page out of search engines**: asks search engines not to list the page.
- **Share image**: the address (http or https) of the image shown when the page is shared.

## What the site serves

For each published page the served HTML carries the title, `<meta name="description">`, `<meta name="robots">`, a canonical link and the Open Graph tags (`og:title`, `og:description`, `og:url`, `og:image`).

A page that is only a draft, and an address with no page, get the portal's name as the title and `noindex`: nothing of an unpublished page reaches the head. The head is read the same way the public content API reads a page, so the API and the HTML never disagree.

The content API returns the same fields as `seo` on each page, so a headless front end can use them too.
