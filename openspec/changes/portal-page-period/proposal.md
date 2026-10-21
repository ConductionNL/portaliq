# portal-page-period

## Why

The PtPortal board draws one period selector above the portal's four traffic figures; every figure follows it. Live,
each figure carried its own period picker.

## What changes

- The portal page declares a page filter `period` (7 days, 30 days, 12 months; 30 by default) in its band, through
  nextcloud-vue's detail page filters (`config.pageFilters`, detail-page-filters).
- A traffic KPI card takes `content.days`, a token its period comes from (`@page.period`). With it the card asks for
  that many days and draws no picker of its own; without it the card keeps its own `dateRange` picker.
