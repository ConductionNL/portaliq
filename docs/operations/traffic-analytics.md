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
| `heatmaps` | Reserved for a later phase. Records where visitors click and scroll on each page. |
| `sessionRecording` | Reserved for a later phase. Replays whole visits, including what is typed. |

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

## What you see

**Reports, Traffic** shows one portal at a time over a period you choose
(the last 7, 30 or 90 days, or a custom start and end): page views,
sessions, visitors and engaged sessions, a chart per day, the top pages
with entrances and exits, the most travelled steps between pages, sources
by channel, and under **Visitors** the new versus returning split where the
portal can tell, the signed in accounts where it links them, and the
devices, browsers, operating systems, languages and regions. A breakdown
the portal did not enable reads **not measured** rather than showing an
empty list. A portal with measurement off says **Not measured for
this portal**; a measured portal without figures yet says **No traffic
recorded yet**. The two are different facts, and neither draws a chart.

The collector's counters are on `/api/metrics` as
`portaliq_traffic_accepted_total` and `portaliq_traffic_refused_total{reason}`.
