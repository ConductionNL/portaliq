---
example: true
capability: configuration-initialization
status: example
built_by: openspec/changes/example-change
---

# Configuration Initialization Specification

> ⚠️ **EXAMPLE SPEC** — This spec lives in the `portaliq` repository
> as a demonstration of the OpenSpec format. It describes the behaviour of
> `lib/Repair/InitializeSettings.php` in the template's own code. Apps built
> from this template will typically keep this capability almost unchanged; the
> only substitutions are the bundled config file name and the schema/register
> IDs that the import produces.

## Purpose

Populates the app's OpenRegister schemas + registers on first install (and
after upgrades that ship a new bundled configuration) from a JSON file
committed alongside the app's PHP code. The work happens inside a Nextcloud
repair step so it runs during `occ maintenance:repair` and during the
install/upgrade flow automatically.

The repair step MUST be non-fatal: a missing or failed import MUST log a
warning and allow the rest of the repair pass to continue. An app that cannot
boot without its registers is a separate, stricter contract and belongs in a
different capability if needed.

## Requirements

@e2e exclude every requirement in this capability describes the inside of a
Nextcloud REPAIR STEP — code that runs during `occ maintenance:repair` and the
install/upgrade flow, before and outside any HTTP request. Its observable
effects on a running instance (the register, the schemas, the seed rows, the
generated signing secret) are already asserted end-to-end by
`tests/e2e/ci-seed.sh`, which verifies each of them and fails the job loudly if
any is absent — but the requirements below are about `IOutput` messages, logger
calls and the arguments handed to `SettingsService`, none of which a browser
session can observe. Covered by `tests/Unit/Repair/InitializeSettingsTest.php`
(`testTheStepNamesItselfAfterThisAppAndWhatItDoes`,
`testHappyPathImportsAndReportsSuccessToTheOperator`,
`testMissingOpenRegisterWarnsOnBothChannelsAndReturnsNormally`,
`testAThrowingImportIsCaughtLoggedAndDoesNotAbortTheRepairPass`).

> ⚠️ **Open divergence between this spec and the shipped code.** REQ-INIT-002
> below says the step MUST call `loadConfiguration(force: true)`.
> `lib/Repair/InitializeSettings.php` passes `force: false`, and the non-forced
> path is version-guarded — it can record "already current" without having
> imported anything, which is precisely why `tests/e2e/ci-seed.sh` performs its
> own forced import rather than trusting this step. Changing the flag alters
> what every upgrade does to an operator's configuration, so it is filed as a
> decision rather than settled here, and the unit test deliberately leaves that
> one argument unasserted rather than certifying either side. Same file, second
> divergence: the catch block logs `['exception' => $e->getMessage()]` — a
> string — where this spec and ADR-005 ask for the exception itself, so the
> stack trace never reaches the log. Both are filed as
> [ConductionNL/portaliq#91](https://github.com/ConductionNL/portaliq/issues/91).

### REQ-INIT-001: Identify the repair step

The system MUST expose a human-readable name for the repair step so that it
appears in Nextcloud's occ repair output.

#### Scenario: Name is surfaced

- WHEN Nextcloud enumerates repair steps
- THEN `InitializeSettings::getName()` MUST return a non-empty string identifying this step
- AND the name MUST mention the app and what the step does ("Initialize Portaliq register and schemas via ConfigurationService" or equivalent after substitution)

### REQ-INIT-002: Import configuration on install / upgrade

The system MUST, when the repair step runs, invoke `SettingsService::loadConfiguration(force: true)`. If OpenRegister is not available, the step MUST log a warning and return without throwing. If the service call throws, the step MUST catch the exception, log the error with context, and continue — it MUST NOT let the failure abort the rest of the Nextcloud repair pass.

#### Scenario: Happy-path first install

- GIVEN the `openregister` app is installed and enabled
- AND the app's bundled `app_template_register.json` is present
- WHEN `InitializeSettings::run()` executes
- THEN the system MUST write a progress message to the repair `IOutput`
- AND the system MUST call `SettingsService::loadConfiguration(force: true)`
- AND on success, it MUST record the result (including schema/register IDs) in the server-side log at info level

#### Scenario: OpenRegister is missing

- GIVEN the `openregister` app is not installed or not enabled
- WHEN `run()` executes
- THEN the step MUST detect the unavailability via `SettingsService::isOpenRegisterAvailable()`
- AND it MUST write a warning to `IOutput` and to the logger
- AND it MUST return normally (no exception) so that subsequent repair steps can run

#### Scenario: ConfigurationService throws

- GIVEN OpenRegister is installed but the bundled JSON is malformed (or any other `ConfigurationService` failure)
- WHEN `loadConfiguration()` throws
- THEN the repair step MUST catch the exception
- AND it MUST log the exception with full context (`$logger->error(message, ['exception' => $e])`)
- AND it MUST write a user-visible warning to `IOutput`
- AND the repair step MUST return normally (the surrounding repair pass keeps running)
