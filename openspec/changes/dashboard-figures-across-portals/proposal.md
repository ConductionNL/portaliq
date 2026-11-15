# Proposal: dashboard-figures-across-portals

## Why

The dashboard board ([portaliq/PtDashboard](https://identity.conduction.nl/screens/board?id=portaliq/PtDashboard)) opens with "Bezoekers, laatste 7 dagen" and "Beschikbaarheid" for the website and Mijn Zuiddrecht together, and has a right column with "Beschikbaarheid" per portal, "Verkeer, laatste 7 dagen" (page views, sessions, visitors, engaged share, most viewed pages) and "Mijn Zuiddrecht nu". The traffic summary and the availability report answer one portal at a time, so the dashboard could not show these figures.

## What Changes

- `GET /api/overview/traffic?days=7|30` and `GET /api/overview/availability`, admin only (the posture of the traffic summary and the availability report, checked by Nextcloud before the method runs).
- `PortalOverview` folds every published portal: traffic totals over the days with the four most viewed pages, and last month's availability per portal, together, with the outages.
- The dashboard gets the KPIs and the three cards as manifest config over nextcloud-vue's `stat`, `stats-block` (grid) and `record-list` widgets with endpoint sources. The live KPIs Portals and Active accounts stay.

## Impact

- `lib/Service/Overview/PortalOverview.php`, `lib/Controller/PortalOverviewController.php`, `appinfo/routes.php`
- `src/manifest.json` (page Dashboard), l10n. No schema change.
- Visitors are added per portal: a person who visited both portals counts twice. The caption says "samen".
