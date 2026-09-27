---
kind: code
---

# Proposal: operate-maintenance-notice

## Why

On Saturday night the case system is down for maintenance. The municipality
wants every visitor of its portal to read that before they start a request,
and wants the message gone on Sunday morning without anyone remembering to
remove it. Today an editor can only write a warning into one page's text,
and the signed-in portal has no place for it at all.

The demand row, portaliq matrix, row `dem-cl-maintenance-notice`, "Show a
maintenance or warning notice to visitors on the portal's pages.", origin
`changelog`,
<https://github.com/nl-portal/nl-portal/blob/3.1.1/documentation/release-notes/3.0.x/app/3.0.3_3.0.2-1.md#L17>.
Rated `partial`, `built.state` `built`. Its `built.note`, verbatim:

> An editor can write a warning into a page's content, but there is no site-wide notice or banner, nothing time-boxed, and nothing on the signed-in /portal SPA.

Three competitors are rated `yes`. `open-inwoner`, verbatim:

> src/open_inwoner/configurations/models.py:74 warning_banner_enabled and text; src/open_inwoner/templates/master.html:101 shown on every page [reached on every page; off by default]

`nl-portal`, verbatim:

> frontend/packages/user-interface/src/pages/OverviewPage.tsx:69 alert behind showAlert; frontend/packages/app/src/constants/routes.tsx:25 OVERVIEW_SHOW_MAINTENANCE_ALERT [reached on overview page (/)]

`liferay-dxp`, verbatim:

> https://learn.liferay.com/w/dxp/content-management-system/alerts-and-announcements 'Alerts are for high-priority information (e.g. planned downtime alerts, security alerts, etc.) and appear with a red Important tag', placed with the Alerts widget. [was unknown]

The lane recorded the row as `build`: a changelog demand row, and two or
more competitors rated `yes`.

## What changes

- **A notice is its own record.** An editor writes a notice for a portal:
  the message, an optional link, a level (information or warning), when it
  starts and when it ends, and where it shows (the public site, the
  signed-in portal, or both).
- **It shows on every page while it is on.** The public site and the
  signed-in portal render the active notices above the page content, in the
  portal's theme. Outside its window a notice is not sent at all.
- **A visitor can close it.** Closing hides that notice for the rest of the
  visit. A new notice shows again.
- **Page editors write notices.** The same groups that may edit pages may
  write notices; administrators always may.

## Rows this closes

| Matrix | Row id | Row name | Own rating | What is missing |
|---|---|---|---|---|
| portaliq | `dem-cl-maintenance-notice` | Show a maintenance or warning notice to visitors on the portal's pages. | partial | A site-wide, time-boxed notice on both the public site and the signed-in portal. |

## Existing work it builds on

- `portaliq-cms` (spec) and `portal-page-designer` (spec): portal content as
  OpenRegister data, and the editor groups.
- `portal-white-label-runtime-config` (open): the runtime config the
  signed-in portal boots from.
- `lib/Controller/ContentController.php` `site()`: the public site's
  presentation record, which gains the active notices.

## Out of scope

- Taking the portal offline. A notice informs; it blocks nothing.
- Sending the notice by mail or push.
- A notice per page. A page's own text already covers that.
