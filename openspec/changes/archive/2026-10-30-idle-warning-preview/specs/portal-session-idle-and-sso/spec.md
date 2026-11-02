## ADDED Requirements

### Requirement: A test instance may open the idle warning on request (REQ-SIS-007)

The app flag `session_idle_preview_enabled` MUST default to `no`. Only when it is `yes` MUST the session answer carry `idlePreview: true`, and only then MAY the site open the idle warning immediately for a signed-in page whose address holds `idleWarning=now`. When the flag is not `yes`, the session answer MUST carry `idlePreview: false` and the site MUST ignore the parameter.

#### Scenario: Measuring the SessieVerloopt board
@e2e exclude Unit tests: PortalSessionServiceTest, tests/idle-warning-preview.spec.mjs
- GIVEN a development instance with `session_idle_preview_enabled` set to `yes`
- WHEN a signed-in resident opens `/mijn?idleWarning=now`
- THEN the idle warning is open at once, with its "Ingelogd blijven" and "Uitloggen" buttons

#### Scenario: A production instance
@e2e exclude Unit tests: PortalSessionServiceTest, tests/idle-warning-preview.spec.mjs
- GIVEN the flag is unset
- WHEN a resident opens a page with `idleWarning=now`
- THEN no warning opens before the idle window has passed
