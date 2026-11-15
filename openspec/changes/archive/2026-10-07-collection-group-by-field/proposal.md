# Proposal: collection-group-by-field

## Why

Found testing a primary school end to end (2026-09-30). learniq declares `groupByField: 'learnerRef'` on a guardian's grades, attendance, absence reports, report cards and conference bookings (learniq `portal-contribution-guardian-audiences`, Decision 2: "a portal client groups the existing flat result set per child"). Portaliq ignored the key, so a parent with two children saw one mixed table and had to tell from a reference column which row was about which child.

## What changes

- The contribution contract documents `groupByField`: a collection MAY name the row field its rows are grouped by. The normaliser keeps it only when it names a field the collection projects (or any field when it projects none), the same rule `closedField` and `branchField` follow.
- Both renderers, the React portal (`PageView.jsx`) and the site (`ContributionPage.vue`), show one table per group, each under its own heading, through one shared helper (`src/shared/collectionGroups.js`).
- The heading is the name of the row the value points at, read from the contribution's `guardianAudience.children` collection (the guardian's own children, scoped by the server like every collection). A value with no such row shows as it is; rows without a value go last under "Other".
- With one group or none, the table renders exactly as before, so a parent with one child sees no change.

## How it composes with the open site PRs

- #1030 changes `CollectionTable.vue` (column labels). This change does not touch `CollectionTable.vue`: it renders the same component once per group.
- #1029 also edits `ContributionPage.vue` (forms and actions). This change only replaces the one `<CollectionTable>` in the table block with a grouped loop plus the ungrouped table, and adds three methods; a merge conflict, if any, is limited to that block.
- #1026 does not touch collection rendering.

## Not changed

- The collection endpoint and the row shape: grouping is presentation only.
- Sorting inside a group keeps the server's order.
