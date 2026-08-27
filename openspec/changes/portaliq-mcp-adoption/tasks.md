# Tasks: portaliq-mcp-adoption

## Implementation Tasks

### Task 1: Delete the scaffold provider and its alias
- **spec_ref**: `openspec/changes/portaliq-mcp-adoption/specs/portaliq-mcp-surface/spec.md#requirement-portaliq-exposes-no-hand-written-mcp-tool`
- **files**: `lib/Mcp/ExampleToolProvider.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN `ExampleToolProvider` is unmodified template scaffold WHEN this task lands THEN the file MUST be deleted and the now-empty `lib/Mcp/` directory removed
  - GIVEN `Application.php` registers `IMcpToolProvider::portaliq` WHEN this task lands THEN that registration and the comment block instructing the reader to keep it MUST both be removed
  - GIVEN the app boots WHEN the container resolves THEN no DI error MUST occur for a missing provider class
- [ ] Implement
- [ ] Test

### Task 2: Declare the read-only dialect on `portalMessage`
- **spec_ref**: `openspec/changes/portaliq-mcp-adoption/specs/portaliq-mcp-surface/spec.md#requirement-a-curated-read-only-dialect-on-the-portal-inbox`
- **files**: `lib/Settings/portaliq_register.json`
- **acceptance_criteria**:
  - GIVEN `portalMessage` declares `search` + `get` with filters `read`, `organisation`, `subjectRef` WHEN the register is imported THEN `McpAnnotationValidator` MUST accept it, because each filter names a real declared property
  - GIVEN each verb WHEN emitted THEN it MUST carry `scope: 'read'`, `readOnlyHint: true` and useful agent-facing `description` prose
  - GIVEN the derived catalog WHEN built THEN it MUST contain no tool id ending in `.create`, `.update` or `.delete`
- [ ] Implement
- [ ] Test

### Task 3: Verify the identity and session schemas stay off, and that reads are subject-scoped
- **spec_ref**: `openspec/changes/portaliq-mcp-adoption/specs/portaliq-mcp-surface/spec.md#requirement-portal-identity-and-session-objects-are-not-tools`
- **files**: `lib/Settings/portaliq_register.json`, `tests/Unit/`
- **acceptance_criteria**:
  - GIVEN `portalAccount`, `portalSession` and `exampleDocument` WHEN the register is imported THEN none MUST carry an `x-openregister-mcp` block, so no tool can return an IdP claims blob or a session `jti`
  - GIVEN `portaliq.portalmessage.search` is invoked on behalf of subject S WHEN results are returned THEN OpenRegister RBAC MUST restrict them to S's own messages, and no other subject's message MUST appear
- [ ] Implement
- [ ] Test

### Task 4: Remove the scaffold provider's test fixtures and update the CHANGELOG
- **spec_ref**: `openspec/changes/portaliq-mcp-adoption/specs/portaliq-mcp-surface/spec.md#requirement-portaliq-exposes-no-hand-written-mcp-tool`
- **files**: `tests/`, `CHANGELOG.md`
- **acceptance_criteria**:
  - GIVEN any test referencing `ExampleToolProvider` WHEN the class is deleted THEN the test MUST be removed rather than stubbed, since it asserted scaffold behaviour with no product meaning
  - GIVEN the suite runs the CI way WHEN measured against a baseline taken first THEN there MUST be zero new failures
  - GIVEN the CHANGELOG WHEN this change lands THEN it MUST record the scaffold deletion and the new read-only inbox surface
- [ ] Implement
- [ ] Test

## Verification

- `openspec validate portaliq-mcp-adoption --type change --strict` passes.
- The `portaliq` MCP catalog contains exactly `portaliq.portalmessage.search` and `portaliq.portalmessage.get`, and nothing else.
- No class in `portaliq` implements `IMcpToolProvider`.

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- PHP verified the CI way in a container, against a baseline measured first — zero new failures
- Scoped PHPCS clean on every touched `lib/` file; `python3 -m json.tool` after every JSON edit
- `@spec` tags point at `openspec/specs/...`, never an archived change path (gate-46)
- No new user-facing strings — i18n N/A
- `openspec validate` passes
