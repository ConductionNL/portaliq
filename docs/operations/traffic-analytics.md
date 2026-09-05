---
title: Measuring portal traffic
sidebar_label: Traffic analytics
---

# Measuring portal traffic

Portaliq can count what visitors look at on a portal, how they move between
pages, and where they arrive from. The numbers follow the vocabulary every
reporting tool already uses (page views, sessions, visitors, engaged
sessions, entrances, exits), so a communications officer reads them without
a glossary. What is different is where the data goes: the collector is a
Portaliq endpoint on the portal's own origin. Nothing is sent to a third
party and no vendor script is loaded.

Measurement is **off** for every portal until an operator switches it on.

## What is collected

Each portal decides which events it accepts. The collector refuses anything
the portal did not enable and counts the refusal, so a misconfigured page is
visible on `/api/metrics` rather than silently ineffective.

| Event | When |
| --- | --- |
| `page_view` | A page is shown, including client side navigation. |
| `session_start` | A visit starts (only when the portal persists a client id, see below). |
| `scroll` | The visitor reached 90 percent of a page. |
| `outbound_click` | A link to another site was followed. |
| `file_download` | A link to a document (pdf, docx, xlsx, zip and the like) was followed. |
| `search` | A page was opened with a search term in `?q=`, `?zoek=` or `?search=`. |
| `form_submit` | A form was submitted. Only the form's id or name is kept, never a value. |
| `form_start`, `form_field`, `form_abandon` | Form analytics: a form was first interacted with, a field was left (its id and how long it had focus), a started form was left unsubmitted. Never a value; see "Form analytics". |
| `page_not_found` | The site answered a request with not found. See "Missing pages". |
| `js_error` | A script on the page threw. The message, the file (without its query string), the line, the column and a short hash of the stack; never the stack. See "Script errors". |
| `email_open`, `email_click` | A mail sent by another app in this instance (pipelinq) was opened or clicked. Written server side only; a browser cannot claim them. |

Each event carries the page location, the referrer and the page title. On
the server, and only when the portal enabled the dimension, it is extended
with the device type, browser family, operating system family, language
and a coarse region, all derived from the request and then discarded. See
"Where visitors are" for how the region is derived.

## What is never stored

- **The IP address.** It is used within the request to derive the region and
  to rate limit, then dropped. No stored field, log line or aggregate
  contains it.
- **The raw user agent.** Only the device, browser and operating system
  families derived from it are kept, and only when enabled.
- **Cookies or browser storage, by default.** See the next section.
- **Anything the portal did not enable.** A dimension the portal did not
  list is stripped before the event is stored.

## Cookieless by default, a persisted id by choice

In the default mode the client writes nothing to the visitor's browser. The
server tells one visitor from another for one day only, by a hash of a daily
salt, the portal, the address and the user agent. The salt rotates every UTC
day and is not kept beyond it, so the hash cannot be reversed and does not
survive the day. Return visits across days are not recognised, and that is
the trade the default makes.

A portal may switch on `traffic.sensitive.persistClientId`. Only then does the
client keep a first party id in the browser (`localStorage`), scoped to that
portal, plus a session id for the tab. This moves the portal out of the
no-impact bracket of the cookie rules and a consent banner is then likely
required. With `traffic.consent.required` on, the client sends only the
`preConsentEvents` until the site calls
`window.portaliqTraffic.consent(true)`, and withdrawing consent removes the
stored id.

Do Not Track and Global Privacy Control are honoured before anything else
runs: the client then sends nothing and stores nothing.

## The four warned switches

Four settings under `traffic.sensitive` change what the portal knows about a
person rather than how much traffic it counts. Each is off unless set to
literally `true`, each shows its warning next to the setting, and the
Traffic page flags a portal that has any of them on.

| Switch | Consequence |
| --- | --- |
| `persistClientId` | An identifier is stored in the visitor's browser, so return visits are recognised. Consent is then likely required. |
| `accountLinking` | The signed in portal user's reference is attached to their events, which links a named person to their browsing. Only defensible with a stated purpose and a privacy assessment. |
| `heatmaps` | Where visitors click and how far they scroll is recorded per page, as positions on the page and never the text under the pointer. See [Heatmaps](#heatmaps). |
| `sessionRecording` | Visits are recorded for replay with every text and typed value masked to its length before it leaves the browser. See [Session recording](#session-recording). |

The four switches are edited as fields of the portal record, each with
its warning in the field's description, and the Traffic page's overview
repeats the warning for every switch that is on. A dedicated editor for
the block (the four switches with a warning card each) was checked and
is not possible on the current `@conduction/nextcloud-vue`: the library
accepts a custom form field in the registry but nothing renders one yet.

## Where visitors are

Each portal chooses how much geography it keeps with
`traffic.regionGranularity`: `none` stores no location at all, `country`
stores the ISO 3166-1 alpha-2 code (`NL`), `region` stores the country and
its subdivision (`NL-NB`). The `region` dimension must also be enabled.
The lookup is offline: the visitor's address is looked up in a database
file in the app's data directory, the code is kept, and the address is
discarded in the same request. No address is ever sent to a third party
to be resolved.

The instance chooses where that database comes from, under
**Administration settings, Portaliq, Visitor geography**:

| Provider | What it is | What it needs |
| --- | --- | --- |
| DB-IP Lite (default) | The free `dbip-city-lite` database, one file per month. | Nothing. The data is licensed CC BY 4.0, which requires attribution: the line "IP Geolocation by DB-IP (https://db-ip.com), licensed under CC BY 4.0" is stored beside the database, shown in the settings, and must appear wherever the regions are published, for example in a privacy statement or a report footer. |
| MaxMind | GeoLite2-City (free with an account) or GeoIP2-City (paid). | A MaxMind account id and a licence key. The key is stored as a sensitive value and never shown again; the settings only say whether one is stored. MaxMind's own attribution line is stored beside the database. |
| None | No geography for any portal, whatever a portal configured. | Nothing. |

The database is fetched the first time a measuring portal asks for a region
and none is installed, by a queued background job rather than inside the
visitor's request. A monthly background job refreshes it, and
`occ portaliq:traffic:geo-refresh` refreshes it on demand (exit 0 when
refreshed and when geography is disabled, exit 1 when the download or the
verification failed). Every download is opened as a database before it
replaces the one in use, so a failed download leaves the previous database
serving and the failure in the log. Until the first download completes,
regions stay empty and nothing else changes.

The file lives under the instance's data directory
(`appdata_<instance>/portaliq/geo/traffic-geo.mmdb`, with the attribution
in `traffic-geo.json` beside it), never in the app source, so an upgrade
does not remove it. A database installed by hand at that path is used as
is.

## What "visitors" means

`visitors` is the number of distinct visitors on a day: distinct daily
hashes in the default cookieless mode, distinct client ids where the
portal persists one. The two cannot be added across days, and the Traffic
page does not pretend otherwise: the visitors tile over a range is the sum
of the daily figures, which counts a returning visitor once per day.

New versus returning is only known where the portal switched on
`traffic.sensitive.persistClientId`: the client then reports whether it
found or created its id on each `session_start`, and the daily record
carries `newVisitors` and `returningVisitors`. In cookieless mode both are
`null`, not zero (the object API returns them as absent fields), and the
Visitors widget says **not available in cookieless mode**. A daily hash cannot say whether it was here yesterday,
and a zero would claim that nobody came back.

## Account linking

With `traffic.sensitive.accountLinking` on, a batch posted by a signed in
portal user carries their portal session bearer, and each stored event
carries `userRef`: the portal account's pseudonymous `subjectRef`, the
same value every other app in the fleet scopes data by. Never a BSN, a
KVK number, an email address or a name. The daily record then counts
distinct references as `accounts`, and the Visitors widget shows
**Signed-in accounts**. Without the switch the bearer is read for nothing
and the field is absent, whatever a client sends.

This ties a named person to their browsing. Only switch it on with a
stated purpose and a privacy assessment, and expect the Traffic page to
flag the portal for it.

## Retention

Raw events are kept for `traffic.retentionDays` (default 90) and then
deleted. Every fifteen minutes a background job rebuilds one daily record per
portal (`portalTrafficDaily`) from the raw events and removes the events past
their retention. The daily records outlive the raw events; they are what the
Traffic page and any export read, through the ordinary object API.

## Turning it on

Open the portal, find the **Traffic measurement** block and set `enabled` to
true. Pick the events and dimensions, or leave them empty for the defaults
(page views and session starts; referrer and page title). The built in site
renderer loads the client on the next page view.

## External pages and Docusaurus sites

A site that Portaliq does not serve can report its traffic here. Create a
portal with `kind: external`, its verified domains and a traffic block, and
no content. Then load the client from the portal's origin:

```html
<script
  src="https://portaal.example/index.php/apps/portaliq/api/traffic-client.js"
  defer
  data-origin="https://portaal.example"
  data-portal="my-external-site"
  data-appPath="/index.php/apps/portaliq"></script>
```

`docusaurus-plugin-portaliq` emits exactly this tag. The collector resolves
the portal by the request's host first and by `data-portal` only when the
host matches no portal, so a page cannot attribute its events to a portal it
merely names. The client posts as `text/plain`, which a browser sends
cross origin without a preflight; the content and traffic endpoints reflect
the origin and never allow credentials.

## The pixel

For a page that cannot run scripts, one event per image:

```html
<img
  src="https://portaal.example/index.php/apps/portaliq/api/traffic/pixel.gif?portal=my-external-site&e=page_view&l=https%3A%2F%2Fexample.org%2Fpage"
  alt=""
  width="1"
  height="1" />
```

It runs the same validation and the same rate limit as the collector.

## Mail events from pipelinq

When pipelinq's app config `blast.traffic_portal` names a portal, it writes
an `email_open` or `email_click` event for every open and click it records,
attributed by campaign (`campaign`, `source`, `medium`) and by pseudonymous
`blastRef` and `contactRef`. The visitor hash for those events is derived
from the contact reference, salted per portal per day, so a mail event and a
later site visit do not link unless the visit carries the same campaign
parameters. Attribution is by campaign, not by person.

## Goals

A goal is an outcome the portal counts as a success. Each portal lists its
own under `traffic.goals`, in the portal's settings:

| Field | Meaning |
| --- | --- |
| `id` | A short token (letters, digits, dash, underscore) that names the goal in the figures. |
| `name` | What the goal is called on the Traffic page. |
| `type` | `page_reached`, `event`, `download`, `form_submitted` or `search`. |
| `match` | What has to happen: `pathEquals` or `pathPrefix` for a page, `eventName` for an event, `fileExtension` for a download, `formId` for a form, `term` for part of a search term. More than one key means all must hold. |
| `value` | Optional. What one conversion is worth, in whatever unit you report in. |

Goals are evaluated by the aggregation job over the day's sessions; the
collector and the client do not change, so adding a goal costs nothing on
the page and applies to the next aggregation. The daily record carries per
goal the **conversions** (sessions that met it at least once), the
**completions** (matching events) and the value times the conversions, plus
`conversionRate`, the share of sessions that met any goal. A visitor who
downloads the same brochure three times is one conversion and three
completions.

## Funnels

A funnel is an ordered path you expect visitors to walk: the campaign
page, then the form page, then the form submitted. Each portal lists its
own under `traffic.funnels`, each with an `id`, a `name` and `steps`, and
each step has a `name` and a `match` in the same shape as a goal's. A
session counts for a step only after it completed the step before, in the
order its events happened, so a visitor who submitted the form and then
wandered to the campaign page counts for the first step only. The daily
record carries per step the sessions that reached it and the drop-off, the
share of the previous step's sessions that did not.

## Form analytics

When a portal enables `form_start`, `form_field` and `form_abandon`, the
client watches the forms the site renders (the landing-page form widget,
and on an external page any `<form data-portaliq-form="...">`) and
reports:

- `form_start` the first time a field of the form gets focus;
- `form_field` when a field loses focus, with the field's id and how many
  milliseconds it held focus;
- `form_abandon` when the page is hidden while a started form was not
  submitted, with the field the visitor was last on;
- `form_submit`, as before, now with the form's id.

**What was typed is never read, never sent and never stored.** The client
reads a field's id or name and the clock, nothing else. The collector
keeps only `formId`, `fieldId`, `lastFieldId` and `ms` on a form event
and strips anything else, so a value cannot reach storage even from a
client that sends one. The Traffic page shows per form the starts, submits,
abandons, the completion rate and the field most people left on, and the
daily record carries per field the average time in it and how often it
was the last one before an abandon.

## Missing pages

The built-in renderer marks its not-found state with
`data-portaliq-status="404"` and names the route it could not find in
`data-portaliq-path`; an external page may put the same attributes on its
own not-found state. When the portal enables `page_not_found`, the client
reports the location and that route, and the Traffic page lists the
requested paths under **Pages, Missing pages**. A broken link in a newsletter then
shows up where the person who can fix it is looking.

## Custom dimensions

A page can attach its own values to what it sends, such as the audience it
was written for. The portal declares each under `traffic.customDimensions`
with an `id` (a short token), a `name` and a `scope`: `session` counts each
visit once, by the first value it carried; `event` counts every event that
carried a value. The page sets a value with
`window.portaliqTraffic.dimension('audience', 'inwoner')` and the client
attaches it as `cd_audience` to everything it sends from then on. The
collector strips any `cd_` parameter whose id the portal did not declare,
which is the same rule every other dimension already follows. Do not
declare a dimension that would identify a person: the id list is the
portal's statement of what it collects.

## Site search

Search terms come from two places and land in one list. The client reads
`?q=`, `?zoek=`, `?search=`, `?query=` and `?zoekterm=` from the page
location, and the built-in renderer's search block reports the term and
the result count itself when a search completes, so a site whose search
does not put the term in the URL is counted the same. Both need the
`search` event enabled, and the term is only stored when the portal
enabled the `searchTerm` dimension, which is not a default: a search box
receives names, case numbers and medical words. The terms are listed under
**Sources, Searches**.

## Segments

A segment is a saved filter over visits: desktop visitors, visits from a
campaign, visits from one region, visits that met a goal. Each portal
lists its own under `traffic.segments`, each with an `id`, a `name` and
`conditions`, all of which must hold:

| Field | Meaning |
| --- | --- |
| `dimension` | What is compared: `channel`, `deviceType`, `browser`, `os`, `language`, `region`, `campaign`, `source`, `medium`, `referrerHost` or `pageReferrer`; `visitorType` (`new` or `returning`, only known where the portal persists a client id); `userRef-present` (`true` or `false`); or `goal:<id>` (`true` when the visit met that goal). |
| `operator` | `is`, `isNot`, `contains` or `startsWith`, ignoring case. |
| `value` | What to compare with. |

The aggregation job writes one extra daily record per segment, with
`segment` set to its id, beside the record for all visits (`segment`
empty). The Traffic page gets a **Segment** selector next to the portal
and the period; every widget follows it. A segment with a condition the
app cannot evaluate (an unknown dimension, an undeclared goal) is left
out rather than reporting zero forever, and the record of a segment you
remove is deleted on the next aggregation.

## Roll-up portals

A portal whose `traffic.rollupOf` names other portals' slugs is a
roll-up: it has no visitors of its own, the collector refuses anything
posted for it, and its daily record is the sum of its members' records
for the day, computed after theirs. Page views, sessions, visitors and
the events add up; pages, referrers, campaigns, goals, funnels and forms
are merged by their key; rates are re-derived from the summed counts.
The record says which portals it sums (`rollupOf`) and how many had data
that day (`members`), and the Traffic page shows **Roll-up of N
portals**. Visitors are a sum, not a distinct count: a visitor of two
members counts twice, because a roll-up has no raw events to tell.

## Scheduled reports

Each portal lists its reports under `traffic.reports`:

| Field | Meaning |
| --- | --- |
| `id`, `name` | A short token, and the name used in the subject line. |
| `cadence` | `daily` (yesterday), `weekly` (the ISO week that ended on Sunday) or `monthly` (the previous month). |
| `recipients` | Nextcloud user ids or e-mail addresses. |
| `sections` | Any of `overview`, `pages`, `sources`, `visitors`, `goals`, `funnels`, `forms`; empty means the overview. |

An hourly background job sends each report once per period, as soon as
the period has ended, with every number set against the period before
("Page views: 1240 (+12% on the previous period, 1107)") and a link to
the Traffic page. The mail is sent in HTML and plain text through the
instance's mail settings; a Nextcloud user also gets an in-app
notification. A new report goes out on the job's next run with the last
complete period's figures, which is how you check the recipients are
right. The period that was sent is remembered per portal and report, so
a report is never sent twice for one period.

## Alerts

Each portal lists its alerts under `traffic.alerts`:

| Field | Meaning |
| --- | --- |
| `id`, `name` | A short token, and the name used in the subject line. |
| `metric` | `pageViews`, `sessions`, `visitors`, `notFound` (hits on missing pages) or `goal:<id>` (conversions of that goal). |
| `comparison` | `above` or `below` a threshold, or `changeAbove` a percentage against the previous period. |
| `threshold` | The value, or the percentage for `changeAbove`. |
| `period` | `day` or `week`. |
| `recipients` | Nextcloud user ids or e-mail addresses. |

`above` watches the current day or week and fires within the hour the
threshold is crossed. `below` and `changeAbove` need the period to be
over before they can say anything, so they judge the last complete day
or week; `changeAbove` stays quiet when the period before had nothing to
compare with. An alert fires once per period, by mail and as a
notification, and not again however many times the job runs that period.

## The read API and the export

The daily records are the reporting API. Read them through the ordinary
object API of OpenRegister, register `portaliq`, schema
`portalTrafficDaily`:

```text
GET /index.php/apps/openregister/api/objects/portaliq/portalTrafficDaily
    ?portal=open-tilburg
    &date[gte]=2026-09-01&date[lte]=2026-09-30
    &segment=desktop
    &_order[date]=asc&_limit=500
```

Filter on `portal`, `date` (with `gte`, `lte`) and `segment` (empty for
all visits; a record from before segments existed has no `segment`
property and counts as all visits); order with `_order[date]`; page
with `_limit` and `_offset`. Each record carries the fields listed under
"Retention" and the phases above: the four headline numbers, the pages,
transitions, referrers, campaigns, devices, browsers, operating systems,
languages, regions, searches, downloads, outbound links, goals, funnels,
forms, missing pages, script errors, custom dimensions and mail events.

For a file, an administrator calls

```text
GET /index.php/apps/portaliq/api/traffic/export
    ?portal=open-tilburg&from=2026-09-01&to=2026-09-30&segment=&format=csv
```

`format=csv` gives one row per portal-day-segment with the scalar
metrics (`portal, date, segment, pageViews, sessions, visitors,
newVisitors, returningVisitors, accounts, engagedSessions,
avgEngagementSeconds, bounceRate, conversionRate`); `format=json` gives
the records whole. A range longer than a year, a bad date or an unknown
format is refused with a reason. The **Export** button on the Traffic
page downloads exactly what the page shows: the selected portal, period
and segment.

## Server-side tracking

A backend that serves pages itself (a form handler, a document server, a
site whose visitors block scripts) can report on a visitor's behalf.
Mint the portal's token once:

```text
occ portaliq:traffic:token open-tilburg
```

The token is printed once and the portal keeps only its hash under
`traffic.serverToken`; minting again replaces it. Then post the
collector's envelope to `POST /index.php/apps/portaliq/api/traffic/server`
with `Authorization: Bearer <token>`, the portal's slug in `portal`, and
the visitor's `remoteAddress` and `userAgent` on the batch or on each
event:

```json
{
  "portal": "open-tilburg",
  "consent": true,
  "remoteAddress": "203.0.113.9",
  "userAgent": "Mozilla/5.0 ...",
  "events": [
    { "name": "page_view", "timestamp": "2026-09-05T10:00:00Z", "sequence": 0,
      "pageLocation": "https://open-tilburg.nl/woo" }
  ]
}
```

The address and the agent go the way a live request's do: read for the
visitor hash, the device family and the region, then dropped. The same
validator, the same per-portal event list and the same refusals apply,
and a browser's own mail events are still refused. A wrong or missing
token answers 401 and stores nothing.

## Importing an access log

Traffic that happened before measurement was on, or on a server that
cannot run the client, can be imported from an Apache or Nginx access
log:

```text
occ portaliq:traffic:import-log open-tilburg /var/log/nginx/access.log     --format=combined --host=https://open-tilburg.nl
```

`--format=combined` reads the standard combined line; `--format=json`
reads one JSON object per line (Nginx `escape=json` names, or the
common shipper names `ip`, `timestamp`, `method`, `path`, `status`,
`ua`). `--host` is the origin the paths belong to. Only a successful
GET of a page becomes a `page_view`: other methods, redirects, errors,
assets (stylesheets, scripts, images, fonts) and bots are skipped, and a
line repeated within the file is counted once. The views go through the
same ingest step as a beacon, per visitor, with the log's address and
agent as the request and old timestamps allowed; the next aggregation
recomputes the days they fall on. Importing the same file twice counts
its views twice, so keep the files you have imported.

## Script errors

When the portal enables `js_error`, the client reports a script error
on the page with the message, the script's host and path without its
query string, the line, the column and a short hash of the stack. The
stack itself is never sent: it can carry a URL with a token in it. The
daily record carries `errors` (message, source, hits, pages) and the
Traffic page lists them under **Script errors**, with "not measured"
for a portal that did not enable the event.

## Page experiments

An experiment shows two or more versions of a page to different visitors
and counts which version meets a goal more often. Each portal lists its
own under `traffic.experiments`:

| Field | Meaning |
| --- | --- |
| `id`, `name` | A short token for the figures, and what the experiment is called on the Traffic page. |
| `status` | `draft` while you set it up, `running` while visitors are split, `stopped` when it is over. Only a running experiment changes a page; a draft is served to nobody and counted for nobody. |
| `page` | The route the experiment runs on, such as `/` or `/contact`. |
| `variants` | At least two, each with an `id`, a `name`, a `weight` (visitors are split in proportion; default 1) and either a `pageRoute` (another published page shown in place of this one, without a reload) or `changes` (a list of `selector` and `text`: the text each matched element gets). A control variant has an empty `changes` list. |
| `goal` | The id of one of the portal's goals. A visit converts when it meets it. |
| `startedAt`, `stoppedAt` | When it was set running and when it was stopped. A visit after `stoppedAt` is not counted; the experiment's figures stay. |

The client decides the variant in the browser, because that is where the
page is changed. The pick is sticky for the visit: derived from the client
id when the portal persists one (so it survives a reload), from a random
per page load otherwise (so it survives the client side navigation of one
visit and nothing is stored, decision 2). Every event of that visit then
carries `experiment` and `variant` in its params; the collector strips a
tag that names no running experiment. A visit takes part in the first
experiment it meets.

The aggregation counts, per variant, the visits and the visits that met
the goal, and compares the two best rates with a two-proportion z-test.
**A winner is named only when every variant has at least thirty visits
and the confidence is at least 95 percent.** Under that the Experiments
widget reads **Not enough data**; the confidence is still shown. The daily
record carries `experiments` with the per-variant counts, the winner and
the confidence; the Traffic page sums the range and derives the verdict
from the sums, never from an average of days.

Text changes go through `textContent`, so a variant can change what an
element says and never add markup or a script. A variant page is another
page of the same portal; the visitor's address bar shows it, and the page
view is counted for the page they actually saw.

## Heatmaps

Off unless `traffic.sensitive.heatmaps` is literally `true`; the switch
carries its warning. When on, the client sends two events the collector
refuses otherwise (`sensitive-off`):

- `heat_click`: where a click landed as fractions of the document width
  and height (never pixels, so a phone and a desk land on the same grid),
  a viewport width bucket, the element's tag and a short selector of tags
  and classes with every id and attribute stripped;
- `heat_scroll`: the deepest point a page view was scrolled to, as a
  fraction, sent when the page view ends.

Nothing else: not the text under the pointer, not the link, not the
value. The daily record carries `heatmaps`, per page a click grid of
fifty by fifty cells with counts and ten scroll deciles, and only while
the switch is on. The Traffic page's **Heatmap** widget draws the grid on
a plain rectangle and the deciles as bars. It is not a screenshot: the
page is public, open it beside the map. A roll-up portal carries no
heatmap; its members' pages are on different sites.

## Session recording

Off unless `traffic.sensitive.sessionRecording` is literally `true`; the
switch carries its warning. When on, the client loads a separate
recorder script from `/api/traffic-recorder.js`, so a portal that records
nothing never downloads the code that could, and only:

- on a portal this app serves. **An external portal never records**, even
  with the switch on: that page's document is not ours, the tag on it may
  sit beside anything, and we cannot promise the masking holds on markup
  we did not render. The collector refuses a chunk for an external portal
  as `external-portal` too.
- after consent, where the portal requires it. The recorder is not even
  requested until `window.portaliqTraffic.consent(true)` is called.

**What a recording holds.** The page's structure and classes, so a replay
lays out like the page, and its stylesheets, each sent once per visit
and referred to by hash from every snapshot after (the built-in site
carries its whole stylesheet inline, and it is the bulk of a snapshot);
the pointer, the clicks, the scrolling, the viewport and the navigations,
each with a time offset. **What it never
holds.** Text and typed values: the recorder writes a text node as its
length and an input as the length of its value, drops scripts, writes
images and frames as grey boxes, and keeps only layout attributes (no
`alt`, `title`, `placeholder`, `href`, `src`, `aria-*` or `data-*`; a
stylesheet link is the one address kept). The collector walks every chunk
again and drops anything else before storage, so a recorder that was
tampered with cannot land a name through this door.

**Bounds.** A chunk is at most 256 KB and a visit at most 2 MB; past that
the recorder stops and the collector refuses (`recording-chunk-too-large`,
`recording-full`, on the metrics endpoint like every refusal). A visit is
one `portalTrafficRecording` object (admin-readable) that expires with the
portal's raw events (`retentionDays`) and is deleted by the aggregation
run. The Traffic page's **Session recordings** widget lists the newest
hundred with when they started, the pages and the duration, and a player
replays one in a frame with `sandbox="allow-same-origin"` and no
`allow-scripts`: the rebuilt document cannot run code, and the blocks it
shows are where text was, not what it said. The overview's warning says
how many recordings exist and how long they are kept.

## What you see

**Reports, Traffic** shows one portal at a time over a period you choose
(the last 7, 30 or 90 days, or a custom start and end) and, where the
portal declared any, one segment at a time, with an **Export** button
that downloads the same selection: page views,
sessions, visitors and engaged sessions, a chart per day, the top pages
with entrances and exits, the most travelled steps between pages, sources
by channel with the searched terms, and under **Visitors** the new versus
returning split where the portal can tell, the signed in accounts where it
links them, and the devices, browsers, operating systems, languages and
regions. Below those, **Goals**, **Funnels**, **Forms**, **Custom
dimensions**, **Script errors**, **Experiments**, **Heatmap** and
**Session recordings**, each saying so when the portal declared none,
did not enable the event or did not accept the switch. A breakdown
the portal did not enable reads **not measured** rather than showing an
empty list. A portal with measurement off says **Not measured for
this portal**; a measured portal without figures yet says **No traffic
recorded yet**. The two are different facts, and neither draws a chart.

The collector's counters are on `/api/metrics` as
`portaliq_traffic_accepted_total` and `portaliq_traffic_refused_total{reason}`.
