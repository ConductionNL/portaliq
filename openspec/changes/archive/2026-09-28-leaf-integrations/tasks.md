# Tasks: leaf-integrations

## Implementation Tasks

### Task 1: Submissions surface — `PortalSubmissions` index + `PortalSubmissionDetail` detail
- **spec_ref**: `openspec/changes/leaf-integrations/specs/portaliq-leaf-integrations/spec.md#requirement-the-forms-leaf-attaches-nextcloud-forms-to-portal-submissions-on-a-submissions-surface-that-exists`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN `portalSubmission` currently has no manifest page WHEN this task lands THEN `PortalSubmissions` (type `index`, route `/submissions`) and `PortalSubmissionDetail` (type `detail`, route `/submissions/:id`) MUST exist, following the `PortalMessages`/`PortalMessageDetail` archetype, with a menu entry
  - GIVEN the index page WHEN columns are declared THEN they MUST be drawn from `appId`, `actionId`, `submittedAt`, `deliveryStatus` — `payloadCopy` MUST NOT be a list column
  - GIVEN the manifest `$schema` (app-manifest-v2) WHEN validated THEN both pages MUST pass schema validation
- [x] Implement
- [x] Test

### Task 2: Declare the three leaf widgets
- **spec_ref**: `openspec/changes/leaf-integrations/specs/portaliq-leaf-integrations/spec.md#requirement-the-talk-leaf-bridges-a-portal-message-to-a-staff-only-talk-conversation`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN `PortalSubmissionDetail` WHEN widgets are declared THEN one MUST be `{"type": "integration", "integrationId": "forms"}` with a layout entry, matching the shape of the existing `document-files` widget
  - GIVEN `PortalMessageDetail` (today a single `data` widget) WHEN this task lands THEN it MUST additionally carry `{"type": "integration", "integrationId": "talk"}`
  - GIVEN `PortalAccountDetail` WHEN this task lands THEN it MUST additionally carry `{"type": "integration", "integrationId": "calendar"}` alongside the existing `account-sessions`/`account-messages` object-lists
  - GIVEN the existing `document-files` widget on `DocumentDetail` WHEN the diff is reviewed THEN it MUST be untouched
- [x] Implement
- [x] Test

### Task 3: `linkedTypes` on the three consuming schemas
- **spec_ref**: `openspec/changes/leaf-integrations/specs/portaliq-leaf-integrations/spec.md#requirement-leaf-adoption-is-declared-in-both-the-manifest-and-the-schema`
- **files**: `lib/Settings/portaliq_register.json`
- **acceptance_criteria**:
  - GIVEN `portalSubmission`, `portalMessage` and `portalAccount` WHEN the register is imported via `ConfigurationService::importFromApp()` THEN each MUST carry a `linkedTypes` declaration matching its adopted leaf (`forms`, `talk`, `calendar` respectively) and the import MUST succeed
  - GIVEN every JSON edit WHEN complete THEN `python3 -m json.tool` MUST pass on the file
  - GIVEN the other twelve schemas WHEN the diff is reviewed THEN none MUST gain a `linkedTypes` block
- [x] Implement
- [x] Test

### Task 4: Pin the ADR-046 boundary with a test
- **spec_ref**: `openspec/changes/leaf-integrations/specs/portaliq-leaf-integrations/spec.md#requirement-integration-leaves-render-on-the-internal-staff-side-only`
- **files**: `tests/Unit/Controller/`, `lib/Controller/ContentController.php` (read-only reference)
- **acceptance_criteria**:
  - GIVEN the portal-edge serialisers (`ContentController`, `ContributionController`) WHEN a response is built for objects of the three touched schemas THEN a test MUST assert the payload contains no `integrationId` key and no `{"type": "integration"}` widget
  - GIVEN a fixture object seeded with a Talk join URL / Forms share URL in a leaf-link position WHEN served through a `/portal/*` serialiser THEN the test MUST fail if the URL appears — proving the check CAN fail before trusting its pass
  - GIVEN `lib/Controller/`, `lib/Middleware/`, `lib/Auth/`, `appinfo/routes.php` WHEN the change is complete THEN the production diff there MUST be empty
- [x] Implement
- [x] Test

### Task 5: Docs + capability spec maintenance
- **spec_ref**: `openspec/changes/leaf-integrations/specs/portaliq-leaf-integrations/spec.md#requirement-integration-leaves-render-on-the-internal-staff-side-only`
- **files**: `README.md`, `openspec/specs/`
- **acceptance_criteria**:
  - GIVEN the README WHEN this change lands THEN it MUST document the adopted leaves, the side of the boundary each lives on, and that visitor-facing surfaces are portal-edge-mediated per ADR-046
  - GIVEN `openspec/specs/` WHEN this change is archived THEN the `portaliq-leaf-integrations` capability spec MUST be synced there listing `leaf-integrations` under its OpenSpec changes
- [x] Implement
- [x] Test

### Task 6: Install the integration registry in portaliq's own bundle

Added during implementation, because without it the other five tasks ship a surface
nobody can see.

- **spec_ref**: `openspec/changes/leaf-integrations/specs/portaliq-leaf-integrations/spec.md#requirement-the-bundle-that-renders-a-leaf-installs-the-integration-registry`
- **files**: `src/main.js`, `src/icons.js`, `tests/leaf-integrations.spec.mjs`, `package.json`
- **acceptance_criteria**:
  - GIVEN `src/main.js` WHEN the app boots THEN it MUST call `installIntegrationRegistry()`, then `registerBuiltinIntegrations()`, then `registerLeafIntegrations()` — portaliq called none of the three, so any integration widget it declared would have resolved to null and rendered nothing
  - GIVEN each adopted leaf WHEN the manifest is read THEN the widget MUST be placed in `config.layout` and its icon MUST be registered in `src/icons.js`
  - GIVEN `npm run check:leaf-integrations` WHEN the bootstrap call is commented out THEN the check MUST fail; a search of the raw file passes, because the surrounding comment names the same functions
- [x] Implement
- [x] Test

## Verification

- `openspec validate leaf-integrations --type change --strict` passes.
- `src/manifest.json` contains exactly three `"type": "integration"` widgets (`forms`,
  `talk`, `calendar`) and validates against app-manifest-v2. The proposal's fourth,
  `document-files` on a `DocumentDetail` page, does not exist on this branch: neither
  the widget nor the page is in `src/manifest.json`. The proposal described a state
  this app was never in, which is also why it read portaliq as a leaf consumer that
  had merely under-adopted, rather than one that had never installed the registry.
- No route under `/portal/` and no `#[PublicPage]` controller changed.
- The boundary test fails on the seeded-URL control fixture and passes on the real serialisers.

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- PHP verified the CI way in a container, against a baseline measured first — zero new failures
- Scoped PHPCS clean on every touched `lib/` file; `python3 -m json.tool` after every JSON edit
- `@spec` tags point at `openspec/specs/...`, never an archived change path (gate-46)
- No new portaliq-owned user-facing strings — i18n N/A (leaf labels translate in nextcloud-vue)
- `openspec validate` passes
