# Tasks: portal-notices-as-drawn

- [x] 1.1 The notices tab as an index-list configured as the board; AlertOutline registered
  - Files: src/manifest.json, src/icons.js, l10n
  - Test: tests/portal-notices-tab-as-a-list.spec.mjs
- [x] 1.2 Header actions, quick and primary action, header meta
  - Files: src/manifest.json
  - Test: tests/portal-notices-tab-as-a-list.spec.mjs
- [x] 1.3 Side cards as drawn
  - Files: src/widgets/PortalSummaryCard.vue, src/lib/portalSummary.js, l10n
  - Test: tests/portal-side-cards-as-drawn.spec.mjs
- [x] 1.4 Live: after the nc-vue bump and the example-set reload, PtMededelingenActies measures against the board
  - Done 25 Nov 2026 (live-portaliq-1) on a sandbox Nextcloud 34.0.6 + MariaDB with OpenRegister main, opencatalogi, dossiq, pipelinq and portaliq development and the Zuiddrecht example set, board beside live at 1440 wide. As drawn: the Mededelingen tab reads Alles 14, Nu zichtbaar, Komend 3 and Concept 1 with their counts, six notices on the first page ("6 van 14"), each with its tile and "<kind> · toont op <where>" (the DigiD notice "Waarschuwing · toont op Mijn omgeving"), Zichtbaar van, Tot and Status; the header offers Website bekijken, Bewerken, Meer and the primary Nieuwe mededeling; the Domein en certificaat card reads Taal Nederlands and the Thema card names the theme (REQ of portal-notices and portaliq-cms). Found and fixed upstream: the header meta read "zuiddrecht · 0 pagina's" (and "localhost · thema vng · 0 pagina's" on open-tilburg with 10 pages) because nextcloud-vue's `CnDetailPage` read the `headerMetaCounts` list before the record loaded and sent `portal=@object.slug` literally; fixed in nextcloud-vue #2383 (merged), and with a local build of nextcloud-vue main the header reads "zuiddrecht · 53 pagina's" and "localhost · thema vng · 10 pagina's". See 1.5. Not as drawn and left: the header meta names the slug where the board names the domain (zuiddrecht.nl) and omits the theme on this sandbox (no thematiq, theme unresolved); the Domein en certificaat card has no domain and certificate rows and the Beheerders card is missing (the sandbox portal has no domain, certificate or named administrators); the traffic KPI row and the home-page note sit above the tabs on every tab (by the manifest's design note), where the board draws the Mededelingen tab without them.
- [x] 1.5 Raise `@conduction/nextcloud-vue` to the first release that contains nextcloud-vue #2383 (the header count of a record-filtered list), so the portal header counts its pages.
  - Raised 2.203.0 -> 2.232.2 (`git tag --contains b56eee751` in nextcloud-vue: v2.232.2 is the first tag with #2383), lockfile and sbom.cdx.json regenerated; `npm run check:specs`, `npm run lint` (0 errors) and `npm run build` green. The header count on the live portal page follows in the live lane.
