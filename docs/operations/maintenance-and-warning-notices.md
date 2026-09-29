---
title: Maintenance and warning notices
sidebar_label: Notices
description: How an editor tells every visitor of a portal about maintenance or a warning, for a set period
---

# Maintenance and warning notices

When the case system is down on Saturday night, every visitor should read that before they start a request, and the message should be gone on Sunday morning without anyone remembering to remove it. A notice does that.

## Writing a notice

Open **Notices** in the Portaliq menu and add one:

- **Portal**: the portal it shows on.
- **Message**: what visitors read, in plain text, at most 280 characters.
- **Level**: information or a warning. A warning stands out more.
- **Shows from** and **Shows until**: the window. The end is required, so no notice stays up by accident. An end that is not after the start is refused with "The end must be after the start."
- **Shows on**: the public site, the signed-in portal, or both.
- **Link** and **Link text** (optional): a page with more information. Only an https address is shown; without link text the link reads "More information".
- **Status**: only a published notice is shown. A draft waits.

The list shows each notice's status and window, so a notice left in draft is easy to spot.

## What visitors see

While its window runs, a published notice shows above the content of every page, in the portal's house style. At most three notices show at once, the one that started last first.

A visitor can close a notice with **Close this notice**. It stays closed for the rest of their visit; any other notice still shows. The next visit shows it again while it runs.

The public site's answer may be kept for up to five minutes, so a notice can appear up to five minutes after its start. It never stays after its end: the page checks the end itself.

A notice informs; it blocks nothing. Visitors can still send forms during the window. A notice is not translated: write one notice per language if the portal serves more than one.

## Who may write notices

The same people who may edit pages: the administrators and the editor groups set in the Portaliq admin settings. Saving those groups also sets who may write notices.
