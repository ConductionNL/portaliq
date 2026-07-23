# Tasks: wmebv-submission-receipts

> WMEBV ontvangstbevestiging + proof log on every successful create-action, a
> fail-safe side-effect boundary, and a form data-minimisation guard.
> Implementation-ordered; small and verifiable.

## Implementation Tasks

- [ ] T01 — Add the `portalSubmission` schema to `lib/Settings/portaliq_register.json` (`subjectRef`, `organisation`, `appId`, `actionId`, `payloadCopy`, `receiptMessageRef`, `submittedAt`, `deliveryStatus`; `publicRead:false`/`publicWrite:false`) and bump the register `version`.
- [ ] T02 — Create `lib/Service/SubmissionReceiptService.php` with `record(subject, organisation, appId, actionId, whitelistedData)` writing a receipt `portalMessage` (ref id, ISO-8601 timestamp, NL/EN B1 body, whitelisted data copy) via `PortalObjectWriter`.
- [ ] T03 — Extend `SubmissionReceiptService::record()` to write the linked `portalSubmission` proof record (`receiptMessageRef` → the receipt id, `deliveryStatus` transitioning pending → delivered).
- [ ] T04 — Make `record()` fail-safe: catch all side-effect failures, never propagate to the caller, log via `ILogger`, and persist `deliveryStatus: failed` (or a minimal fallback record) for retriability.
- [ ] T05 — Call `SubmissionReceiptService::record()` after the authoritative domain write in `ContributionController::create()` and the create branch of `ContributionController::action()`, passing the WHITELISTED persisted field map (never the raw request body).
- [ ] T06 — Add NL + EN B1 receipt-body i18n keys (English key names) for subject line and body.
- [ ] T07 — Add the data-minimisation guard to `PortalManifestNormaliser::normaliseFieldConfigs()`: drop `required: true` unless the field is in the action schema's `required` set (resolved via `PortalSchemaReader`); drop fail-closed when the schema is unresolvable.
- [ ] T08 — Unit test `SubmissionReceiptServiceTest`: receipt written with whitelisted copy; proof record linked; no receipt on failed create; side-effect throw leaves create unaffected and logs `deliveryStatus: failed`.
- [ ] T09 — Unit test `PortalManifestNormaliserTest`: `required:true` dropped on a non-mandatory field, preserved on a mandatory field, dropped when schema unresolvable.
- [ ] T10 — Unit test `ContributionControllerTest`: successful create triggers `record()` with the whitelisted map; failed create does not; tenant/subject scope of the receipt and proof record.
- [ ] T11 — Add Playwright e2e: submit a create-action through the SPA and assert an ontvangstbevestiging appears in the inbox (closes the `@e2e exclude` markers on the receipt scenarios).
- [ ] T12 — Document `portalSubmission`, the receipt behaviour, and the data-minimisation guard in `README.md`; add the receipt requirement to the canonical `openspec/specs/supplier-portal` spec on sync.
- [ ] T13 — Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan, unit) and `npm run lint` green; run the relevant Hydra gates (spdx-headers, forbidden-patterns, stub-scan, spec-coverage, e2e-coverage).
