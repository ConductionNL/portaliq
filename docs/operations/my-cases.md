---
title: My cases
sidebar_label: My cases
description: How a case app puts its cases on the resident's My cases page, and how it tells open cases from closed ones
---

# My cases

A signed-in resident or company sees every case from every app on one page, **My cases** ("Mijn zaken"). The newest case comes first. Each row names the app it comes from, and opens the case on that app's own page.

## How a case app joins the list

The page reads every contribution collection that declares `kind: cases`. A collection without it stays off the page, however case-like its rows are.

```php
[
	'id' => 'mijnZaken',
	'register' => 'dossiq',
	'schema' => 'case',
	'scopeField' => 'portalSubject',
	'kind' => 'cases',
	'closedField' => 'endDate',
	'fields' => ['title', 'status', 'created', 'endDate'],
]
```

- `kind: cases` puts the collection's rows on the page. The portal shows **My cases** in its menu as soon as one collection declares it, and opens on that page.
- `closedField` names the field that makes a case closed once it holds a value. The portal keeps it only when the field is in `fields`, or when the collection projects no `fields` at all; otherwise it drops it and every case counts as open.
- The row's name is its `title`, else `name`, `reference` or `identifier`. The page also shows `status` and the `created` date when the row carries them.

## Open and closed

With at least one collection declaring `closedField`, the page shows two tabs, **Open** ("Lopend") and **Closed** ("Afgerond"), each with its count. A case of a collection that declares no `closedField` is always open. With no `closedField` anywhere, the page shows one list and no tabs.

## Opening a case

A row opens the page of its own app that shows the collection, with the case selected. When no page of the app shows the collection, the row is plain text, not a link.

## Acting for someone else

A person who holds a mandate sees **Acting for** ("Namens") in the portal header, with **Yourself** ("Uzelf") and every mandate by its label. The choice holds for the rest of the session.

- **Yourself** lists only the person's own cases.
- A mandate lists the person's own cases plus the cases that mandate reaches. Each of those shows the mandate's label, so the person sees why they may read it.
- A mandate whose party tree is larger than the portal's bound lists nothing and says "This organisation has too many cases to list here. Choose a narrower mandate."
- A case opened under a mandate shows read-only: "You are viewing this case on behalf of \{label\}. It cannot be changed here." Nothing can be changed, added or withdrawn, and no documents are listed.

A case app opens its cases to mandates by declaring `mandateField` on the collection: the field that holds the party the case belongs to. A collection without it is never read through a mandate. Mandates are recorded on `portalMandate` by staff, or by a granted access request.

## What it reads

`GET /portal/api/my-cases` with the portal bearer answers `{cases, mandates, activeMandate}`. It takes `mandate=<id>` to act under a mandate, and `mandate=self` for yourself. The case screen takes the same `mandate` on `GET /portal/api/citizen/cases/{register}/{schema}/{id}`. Each case carries `_source` (`appId`, `label`, `register`, `schema`, `collection`) and `_closed`. `GET /portal/api/contributions` announces the page as `cases: {enabled, closedMarker}`.

To check it: declare `kind: cases` and `closedField` on a test collection, give a test resident one case with an end date and one without, and sign in. **My cases** lists the open case under **Open (1)** and the other under **Closed (1)**.
