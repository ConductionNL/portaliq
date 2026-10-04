# Lane log: r2-portal-scope (portaliq)

Resume rule: read this file, `git status`, continue from the first step not marked done.

## portal-scope-list-membership: DONE

- Branch `fix/portal-scope-list-membership`, cut `--no-track` from origin/development d8d2b34. Commits bee8d7c (fix + openspec change + README) and e8fe24e (tenant tests), pushed.
- PR https://github.com/ConductionNL/portaliq/pull/750 (base development, not merged).
- What: shared trait `lib/Service/PortalScopeMatch.php` (equal string/int single value, or strict membership of a string/int element in a real list; fails closed on an empty scoping value, null/absent, empty list, associative array, nested list, any other type). Used by `PortalObjectReader::verifyScope` (list read, detail read, portalAccount lookup) and `PortalObjectWriter::fetchOwnedObject` (update, mark-read, status transitions, PR 745 upload). Update re-stamps a verified list with the stored list. Create stamps `[subjectRef]` when the schema types the scope field `array` (optional `?PortalSchemaReader` constructor arg, the ActionConfigNormaliser pattern; the three controllers that `new` the writer keep working).
- PR 745's upload endpoint needs no edit: it proves ownership with `readObject` and writes with `updateObject`. Only overlap with #745/#749: the one-line change list in the main contract spec.
- Hardening: an empty scoping value used to match rows with an absent/empty scope field; now matches nothing. No caller relies on it.
- Verified: openspec validate 0; php -l 0; phpcs/phpmd/phpstan/psalm on touched lib 0; reader+writer PHPUnit 53 tests 0; new tests fail on the old code (control run, 5 red as expected); check:strict 1 (lint/phpcs/phpmd/psalm/phpstan pass, test:all 1695 tests, 0 failures, 29 inherited class-not-found errors); npm lint 0; format 0; check:specs 0; check:schema-l10n 1 (11 inherited); hydra gates with base origin/development 3 (gate-16 PASS, gate-47 PASS, 72 PASS; FAILs gate-14 StoreController inherited, gate-112 scaffold postman inherited, gate-53 environmental ESM load of build_effective_manifest.js).
- opsx-verify: 1 WARNING (no test that a list match still runs the tenant check) fixed in e8fe24e; 1 SUGGESTION left (trait has no own test file).
- Left: create stamps subjectRef, not the scopeClaim value (separate contract decision); learniq Submission.required still lists learnerIds/tenant_id (learniq schema, noted in #745).
- Time: about 1 h 30 min.
