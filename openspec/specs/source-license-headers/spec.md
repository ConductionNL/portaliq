# source-license-headers Specification

## Purpose
TBD - created by archiving change complete-spdx-headers. Update Purpose after archive.
## Requirements

@e2e exclude source-header presence is a STATIC property of the checkout, not a
runtime behaviour: nothing a browser can do to a running instance changes
whether a `.php` file on disk carries an SPDX line, so no end-to-end test could
distinguish a compliant tree from a non-compliant one. It is enforced
mechanically instead, on every PR, by hydra gate-1 (`spdx-headers`) and by
REUSE via `REUSE.toml` — both of which fail the build, which is strictly
stronger than an e2e assertion would be. (The exclusion previously sat only
under the second scenario, leaving the first one uncovered for the same reason
that applies to both.)

### Requirement: Every lib PHP file carries the EUPL-1.2 SPDX header

Every PHP file under `lib/` MUST carry the EUPL-1.2 licence/copyright header in its top
docblock (`@copyright` Conduction B.V., `@license EUPL-1.2`, `SPDX-License-Identifier:
EUPL-1.2`, `SPDX-FileCopyrightText`), matching the majority of the tree and the repository
`LICENSE`/`composer.json`/manifest (all EUPL-1.2). No `lib/` PHP file — specifically
including `lib/Repair/InitializeActions.php` and `lib/Service/ActionAuthService.php` — may
ship without it.

#### Scenario: The two previously-unheadered files declare their licence

- **WHEN** `lib/Repair/InitializeActions.php` and `lib/Service/ActionAuthService.php` are inspected
- **THEN** each MUST contain `@license EUPL-1.2`, `@copyright`, and `SPDX-License-Identifier: EUPL-1.2`

#### Scenario: The spdx-headers gate passes at 100%

- **WHEN** the `spdx-headers` gate scans `lib/`
- **THEN** the count of `lib/**/*.php` files with `SPDX-License-Identifier` MUST equal the total count of such files (27/27)

@e2e exclude source-header presence is a static REUSE/gate check, not a runtime UI flow.

