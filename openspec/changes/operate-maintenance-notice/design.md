# Design: operate-maintenance-notice

Read at portaliq `development` `eeda3fa`.

## What exists

- `lib/Controller/ContentController.php:211-247` `site()` returns the
  resolved portal's title, slug, theme, logo, tagline, locales, locale,
  authentication modes and traffic config. No notice.
- `ContentController::publicJson()` (line 518) caches the anonymous response
  for 300 seconds (`public, max-age=300, must-revalidate`).
- `src/site/App.vue:54` renders the site header and `:181` the `<main>`.
- `src/portal/main.jsx:29` boots the signed-in portal from the
  `runtimeConfig` initial state; `src/portal/App.jsx:319` renders the header
  and `:357` the `<main>`.
- `src/site/components/FederatedSearchBlock.vue:93` already uses the NL
  Design `utrecht-alert` markup.
- No schema, service or component for a notice exists
  (`grep -riE 'maintenance|announcement|banner' src/site src/portal`
  finds none, per the matrix's `built.evidence`).

## D1. A notice is a `portalNotice` object

New schema in `lib/Settings/portaliq_register.json`:

| Property | Type | Meaning |
|---|---|---|
| `portal` | string | The portal slug. Required. |
| `message` | string | Plain text, at most 280 characters. |
| `linkLabel`, `linkUrl` | string | Optional "More information" link, `https` only. |
| `level` | enum | `info` or `warning`. |
| `startsAt`, `endsAt` | date-time | The window. `endsAt` is required, so no notice is forever by accident. |
| `surfaces` | array | Any of `site`, `portal`. |
| `status` | enum | `draft` or `published`. |

Its `authorization` gives `create`, `update` and `delete` to the editor
groups, written by `PageEditorService` as it does for `page`. Read is
`public` with `status: published`, as `page` does.

## D2. The server decides what is active

`lib/Service/PortalNoticeReader.php::active(string $portal, string $surface)`
returns published notices for that portal and surface whose window contains
now, newest first, at most three. `ContentController::site()` adds
`notices` for surface `site`; `PortalPageController::index()` adds `notices`
to the runtime config for surface `portal`.

Each notice carries its `endsAt`. Because the anonymous site response is
cached for up to 300 seconds, the client also hides a notice whose `endsAt`
has passed. A notice may appear up to five minutes late; it never outstays
its window.

## D3. One component per SPA, same markup

- `src/site/components/SiteNotices.vue`, placed between the header and
  `<main>` in `App.vue`.
- `src/portal/components/PortalNotices.jsx`, in the same place in `App.jsx`.

Both render `utrecht-alert` with the level's modifier, inside a
`<section aria-label="Notice">`. Not `role="alert"`: a notice that is there
on every page load must not interrupt a screen reader on every page. A close
button labelled "Close this notice" stores the notice id in
`sessionStorage` (wrapped in try and catch, so a blocked storage just means
the notice stays).

## D4. Editors manage notices on an admin page

`src/manifest.json` gains `Notices` (`/notices`, index over `portalNotice`)
and `NoticeDetail` (`/notices/:id`), and a menu entry "Notices". The detail
form shows the window in the editor's own time zone and refuses an `endsAt`
before `startsAt`.

## Risks

- **A stale cache shows a notice late.** Bounded to 300 seconds by D2.
- **A notice left in draft.** The list shows status and window, so an editor
  sees it did not go out.

## What this deliberately does not do

- It does not stop anyone from submitting a form during maintenance.
- It does not translate the message; an editor writes one notice per locale
  if the portal serves more than one.
