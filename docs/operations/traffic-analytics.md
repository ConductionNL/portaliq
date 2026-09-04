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
and a coarse region, all derived from the request and then discarded.

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

**Reports, Traffic** shows the last 30 days for one portal at a time: page
views, sessions, visitors and engaged sessions, a chart per day, the top
pages with entrances and exits, the most travelled steps between pages, and
sources by channel. A portal with measurement off says **Not measured for
this portal**; a measured portal without figures yet says **No traffic
recorded yet**. The two are different facts, and neither draws a chart.

The collector's counters are on `/api/metrics` as
`portaliq_traffic_accepted_total` and `portaliq_traffic_refused_total{reason}`.
