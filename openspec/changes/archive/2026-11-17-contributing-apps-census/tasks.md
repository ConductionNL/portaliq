# Tasks: contributing-apps-census

- [x] **T01**: `lib/Service/ContributionCensus.php`: per enabled app (`IAppManager::getEnabledApps()`), locate the provider, read its audiences (`getAudiences()` or `getAudience()`), and count collections, pages and actions of its contribution for a synthetic subject per audience (no subject reference of a real person); catch a throwing provider per app; opencatalogi listed as integrating through its endpoints (REQ-CAC-001). Verification: `ContributionCensusTest` with a contributing, a missing and a throwing provider.
- [x] **T02**: `lib/Command/Contributions.php` (`portaliq:contributions`, table and `--output=json`), registered in `appinfo/info.xml` (REQ-CAC-001). Verification: `ContributionsCommandTest`.
- [x] **T03**: Docs page `docs/operations/contributing-apps.md`: how to read the census and what "contributes nothing" means for an app.
- [x] **T04**: Run the command on the dev instance and paste its output in the build PR.
  - Live pass 2026-11-17 on a fresh NC 34.0.6 with openregister, portaliq, dossiq, integriq and thematiq: `occ portaliq:contributions` exits 0. dossiq: supplier 6 collections, 6 pages, 1 action; citizen, client and business 5, 5, 10 each; inspector 2, 2, 1. portaliq: citizen 1, 1, 1; supplier 3, 2, 3; anonymous 0, 0, 1. Every other enabled app (integriq and openregister included) "contributes nothing". The full output is in the live-pass PR.
- [x] **T05**: `openspec validate contributing-apps-census --strict`.
  - Valid, openspec 1.12.0, 2026-11-17.
