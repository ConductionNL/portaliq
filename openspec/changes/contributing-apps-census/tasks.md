# Tasks: contributing-apps-census

- [ ] **T01**: `lib/Service/ContributionCensus.php`: per enabled app (`IAppManager::getEnabledApps()`), locate the provider, read its audiences (`getAudiences()` or `getAudience()`), and count collections, pages and actions of its contribution for a synthetic subject per audience (no subject reference of a real person); catch a throwing provider per app; opencatalogi listed as integrating through its endpoints (REQ-CAC-001). Verification: `ContributionCensusTest` with a contributing, a missing and a throwing provider.
- [ ] **T02**: `lib/Command/Contributions.php` (`portaliq:contributions`, table and `--output=json`), registered in `appinfo/info.xml` (REQ-CAC-001). Verification: `ContributionsCommandTest`.
- [ ] **T03**: Docs page `docs/operations/contributing-apps.md`: how to read the census and what "contributes nothing" means for an app.
- [ ] **T04**: Run the command on the dev instance and paste its output in the build PR.
- [ ] **T05**: `openspec validate contributing-apps-census --strict`.
