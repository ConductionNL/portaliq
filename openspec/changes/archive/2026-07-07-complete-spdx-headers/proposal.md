---
kind: config
---

# Proposal: complete-spdx-headers

## Why

portaliq is cleanly licensed EUPL-1.2 and consistent about it: the manifest already declares
`<licence>eupl</licence>`, and 25 of its 27 `lib/**/*.php` files carry the
`SPDX-License-Identifier: EUPL-1.2` header. But **two files miss it** —
`lib/Repair/InitializeActions.php` and `lib/Service/ActionAuthService.php` — so the
`spdx-headers` quality gate (which requires the header on *every* `lib/` PHP file) fails on
this repository, and those two files ship with no machine-readable licence/copyright
provenance. This is the only substantive readiness finding for portaliq: it is a
recently-created app whose known feature gaps are already in flight as active changes
(`field-projection` covers read-side field projection, plus `contract-v2`,
`reverse-scope-join`, `supplier-portal`), and the external-IdP (eHerkenning/DigiD) broker is
owned by openconnector's `portal-idp-broker`, not portaliq.

## What Changes

- Add the EUPL-1.2 licence/copyright header docblock (matching the other 25 lib files:
  `@copyright` Conduction B.V., `@license EUPL-1.2`, `SPDX-License-Identifier: EUPL-1.2`,
  `SPDX-FileCopyrightText`) to the two files currently missing it:
  `lib/Repair/InitializeActions.php` and `lib/Service/ActionAuthService.php`. No code logic
  change.

## Impact

- Affected: 2 `lib/**/*.php` files (header docblock only). No behavioural change.
- Brings the `spdx-headers` gate to green (100% of lib files headered) and completes REUSE
  compliance.
