# Tasks: complete-spdx-headers

- [ ] 1.1 Add the EUPL-1.2 licence/copyright header docblock (matching the other 25 lib files) to `lib/Repair/InitializeActions.php` and `lib/Service/ActionAuthService.php` (Edit tool; preserve existing docblock content; no logic change).
  - **spec_ref**: `specs/source-license-headers/spec.md#requirement-every-lib-php-file-carries-the-eupl-12-spdx-header`
  - **acceptance_criteria**:
    - Both files contain `@license EUPL-1.2` + `@copyright` + `SPDX-License-Identifier: EUPL-1.2`
    - Header-only diff
- [ ] 1.2 Verify: `grep -rL 'SPDX-License-Identifier' lib --include='*.php'` returns nothing; spdx-headers gate green (27/27); `openspec validate complete-spdx-headers --strict` clean.
  - **spec_ref**: `specs/source-license-headers/spec.md#requirement-the-spdx-headers-gate-passes-at-100`
  - **acceptance_criteria**:
    - Zero lib PHP files missing the SPDX header
