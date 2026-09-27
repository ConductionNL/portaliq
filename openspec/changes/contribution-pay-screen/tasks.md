# Tasks: contribution-pay-screen

Kind: code. Decision D30 (learniq round 1 decisions, 27 September); builds D1
and D2 of `case-actions-sign-a-document`.

## Implementation Tasks

### Task 1: Row action resolution and the notice field
- **spec_ref**: `openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-server-enforced-status-transitions`
- **files**: `lib/Contribution/RowActionResolver.php`, `lib/Contribution/CollectionConfigNormaliser.php`, `tests/Unit/Contribution/RowActionResolverTest.php`
- **acceptance_criteria**:
  - GIVEN an endpoint action with rowField WHEN a collection names it by rowAction or rowActions THEN it resolves; without rowField it does not
  - GIVEN a malformed rowWhen or noticeField WHEN normalised THEN the action is not a row action, the notice key is dropped
- [x] Implement
- [x] Test

### Task 2: The row-scoped forward
- **spec_ref**: `openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-a-row-scoped-forward-must-prove-the-row-before-it-forwards`
- **files**: `lib/Controller/PortalRowActionController.php`, `lib/Service/PortalActionForwarder.php`, `appinfo/routes.php`, `tests/Unit/Controller/PortalRowActionControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a guardian's own invoice WHEN forwarded THEN the body is exactly `{invoiceId: <row id>}` and the answer is relayed
  - GIVEN a foreign row, an action not offered, low trust or a row outside rowWhen WHEN forwarded THEN 404, 403, 403, 409 and no call
- [x] Implement
- [x] Test

### Task 3: The pure row action module and the api call
- **spec_ref**: `openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-the-portal-must-let-a-guardian-pay-a-school-contribution-from-its-row`
- **files**: `src/portal/lib/rowAction.js`, `src/portal/lib/portalApi.js`, `tests/row-action.spec.mjs`, `package.json`
- **acceptance_criteria**:
  - GIVEN an answer WHEN redirectTarget runs THEN only an absolute https URL from a 2xx answer comes back
  - GIVEN each status class WHEN outcomeKey runs THEN one message key comes back
- [x] Implement
- [x] Test

### Task 4: The pay screen in the portal
- **spec_ref**: `openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-name-a-notice-field`
- **files**: `src/portal/components/RowActionConfirm.jsx`, `src/portal/components/CollectionTable.jsx`, `src/portal/components/PageView.jsx`, `src/portal/i18n/en.json`, `src/portal/i18n/nl.json`, `src/portal/theme.css`, `tests/row-action.spec.mjs`
- **acceptance_criteria**:
  - GIVEN an issued and a paid contribution WHEN the table renders THEN only the issued row has the button
  - GIVEN a voluntary contribution WHEN the detail or the confirm step shows THEN the notice is there
- [x] Implement
- [x] Test

### Task 5: Docs
- **spec_ref**: `openspec/changes/contribution-pay-screen/contract.md`
- **files**: `docs/operations/row-actions-and-payments.md`
- **acceptance_criteria**:
  - GIVEN a leaf app author or a school operator WHEN reading the page THEN the keys, the forward and the shillinq settings are described
- [x] Implement

## Verification
- [ ] `openspec validate contribution-pay-screen`, diff-scoped checks, `composer check:strict` once, `npm run lint`, `npm run format`, `npm run check:specs`, hydra gates

## Quality checklist

- PHPUnit for the resolver and the controller; node tests for the pure module and the rendered table and confirm step.
- No schema change, so no seed data, no register version bump, no schema l10n keys.
- SPA strings in `src/portal/i18n/en.json` and `nl.json`.
- No em-dashes, sentence case in every new string.
