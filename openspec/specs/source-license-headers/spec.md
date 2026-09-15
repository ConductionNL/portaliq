# source-license-headers Specification

## Purpose
TBD - created by archiving change complete-spdx-headers. Update Purpose after archive.
## Requirements

@e2e exclude source-header presence is a STATIC property of the checkout, not a
runtime behaviour: nothing a browser can do to a running instance changes
whether a `.php` file on disk carries an SPDX line, so no end-to-end test could
distinguish a compliant tree from a non-compliant one. It is enforced
mechanically instead, on every PR, by hydra gate-1 (`spdx-headers`) and by
REUSE via `REUSE.toml`. (The exclusion previously sat only under the second
scenario, leaving the first one uncovered for the same reason that applies to
both.)

Only ONE of those two currently fails the build, and the sentence above used to
claim both did. `quality / REUSE compliance` runs `fsfe/reuse-action@v5` under
`continue-on-error: ${{ !inputs.reuse-blocking }}`, and this app does not set
`reuse-blocking`, so it takes the fleet default of `false` and the job reports
success whatever `reuse lint` concludes. What holds this line meanwhile is
`check:reuse` (`scripts/check-reuse.js`), which does fail the build; see
`.github/workflows/code-quality.yml` for the single blocker that keeps
`reuse-blocking` off and who owns it.

A second correction, to the record rather than to the requirement. #521's
commit message states that five openspec documents were "SKIPPED ENTIRELY"
because they wrote the SPDX tag and its value inside one code span. Measured on
#553 by reverting the prose and re-running the linter, that overstates it: all
1,349 files resolved byte-identically with the prose restored, because REUSE
skips only a file's *own* extracted information and the `**/*.md` table then
supplies it. The real delta was ten stderr ERROR lines. The documents were
never unlicensed; their headers were unreadable, which is a smaller and
different defect, and it is now fixed with `.license` companions instead of by
rewriting normative prose. Cite this paragraph rather than that commit message.

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

