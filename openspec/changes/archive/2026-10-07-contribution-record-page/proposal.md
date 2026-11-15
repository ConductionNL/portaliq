# Proposal: contribution-record-page

## Why

Ruben reviewed the primary-school parent portal on 2026-10-02. A parent who opens "Mijn kinderen" sees a table with two names and nothing else. The report cards, grades, attendance and conference times sit on separate pages, mixed across children. He asked for one page per child, with figures at the top (absence, late arrivals, unexcused absence), the child's report cards, homework and attendance, a calendar of what is coming, and the school news.

Portaliq could not express that. A page could only list collections side by side, every collection showed all of the guardian's rows, and there was no block for a figure, a calendar or the news.

## What changes

- A page MAY declare `record: {collection, titleFields}`. The page opens on the rows of that collection. Picking one, or a parent with one row, opens the record: a heading with the record's name, a way back to the list, and the page's other blocks narrowed to that record.
- A `collection`, `kpi` or `calendar` block MAY declare `recordField` (and `recordKey`, default `id`): on an open record only rows whose `recordField` equals, or holds, the record's `recordKey` value show. The narrowing is presentation only. The server still scopes every read to the subject, so a record outside the subject's own rows cannot be opened, and a link to one says so.
- A block or calendar source MAY declare `recordGroupsField`: a row bound to groups (a school trip for one class) shows only for the record's groups, read from the contribution's `guardianAudience.groups`.
- A `collection` block MAY declare `lookups` that copy one value from a second collection onto each row: a homework row shows whether the child handed it in.
- A new `kpi` block shows figure cards from one row of a collection: per card a `field`, a `label`, an optional `unit`, optional `details` and an optional `highlight`. `pick` chooses the row (the highest value of a field, for example the latest school year).
- A new `calendar` block shows dated rows from one or more collections, as a list of what is coming and as a month view. Each source maps its own fields (`startField`, `endField`, `titleField`, a `kind` label) and MAY expand a list field held on each row (holidays held on a report period).
- A new `news` block shows the subject's latest news items from the guardian news feed, on an open record only those whose target is that record's school, group or the record itself.
- The normaliser keeps each new key only in its valid shape and drops a block whose collection does not resolve in the same contribution, as for every block.

## Not changed

- The collection endpoint, the scope rules and the `via` join: one hop stays the law.
- Navigation: a record page is an ordinary page in the menu.
- A page without `record` renders exactly as before.
