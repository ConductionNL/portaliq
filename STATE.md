# portaliq: real state of `development` (measured 2026-10-08)

Method: the 60 newest completed `development` push runs of `code-quality.yml`, walked newest first, first
conclusion per job that is not `skipped` (and, for jobs that only ever showed `cancelled` on push, the nightly
`workflow_dispatch` runs on `development`, which run at about 03:20 UTC and are the repo's nightly sweep).
Every job not listed below was `success` in run 37777205059 / 37778449646 (de87cbba5), including all eight
PHPUnit cells (PHP 8.3 and 8.4 x NC stable32 to stable35, pgsql), Newman, psalm, phpstan, phpcs and Hydra Gates.

## Not green

| Job | Real state | Run | Commit | Cause |
|---|---|---|---|---|
| PHP Quality (phpmd) | failure | 37778449646 (push) | de87cbba5 | SessionController ExcessiveClassLength 1087 > 1000, TooManyMethods 26 > 25, exampleResidentUser() cyclomatic 13 / NPath 200; PortalShell ExcessiveClassComplexity 59 > 50. Reproduced locally on fe164f6b (exit 2, same five findings). PortalRuntimeConfigResolver does not show up on fe164f6b. |
| Quality Report | failure | 37778449646 (push) | de87cbba5 | Aggregate: red because phpmd is red. |
| E2E Tests (Playwright) | failure | 37722397732 (nightly dispatch) | 5786ec84b | Never runs on a push to development (`e2e-promotion-only: true`, declared skip). In the nightly it now runs against stable35 and dies in the seed: `occ` fails with `Call to undefined method Symfony\Component\Console\Application::addCommand()` (server/core/register_command.php:125) right after portaliq is enabled. portaliq's dev install vendors symfony/console 6.4.43 (via consolidation/robo 4 from edgedesign/phpqa, and psalm), and the app's vendor autoloader is prepended, so it shadows Nextcloud 35's console 7.4. Same failure in 37587378598 and 37566394082. Before stable35 became the e2e server (nightlies of 10-04 and 10-06 on stable34) the suite ran and reported 68 and 60 failed specs; what remains after the seed is fixed is not yet known. |
| Hydra Gates (axe) | not run | none in 60 push runs or the nightlies | | Depends on the Playwright axe report; `enable-axe` is deliberately unset in code-quality.yml. Only `cancelled` entries seen. Not a red. |
| Journeydoc Capture (screenshots) | not run | none | | Only `cancelled` entries seen on push; not enabled on the nightly. Not a red. |

## Parallel work found

- phpmd: open PR #1400 (fix/phpmd-session-portalshell, WilcoLouwerse, updated 14:24 today) splits the demo
  sign-in into ExampleResidentController and the resident menu into PortalResidentMenu, and reports phpmd exit 0.
  Left to that PR.
- E2E seed: the same failure was fixed in thematiq#712 (`"prepend-autoloader": false`) and openregister#3775.
  No open portaliq PR for it.

## Outcome (16:55)

- phpmd and Quality Report: #1400 merged at 14:32 (1cc8957d). phpmd on the new development tip is clean
  locally (check:strict phpmd section, no findings). Development push CI on 1cc8957d will confirm.
- E2E seed: PR #1416 (fix/dev-green-autoloader-order), `"prepend-autoloader": false`. Reproduced and verified
  on a local stable35 install. The specs behind the seed (68 and 60 failed on the last stable34 nightlies) are
  unmeasured on stable35 until the seed passes; that is the next red after #1416 lands.
