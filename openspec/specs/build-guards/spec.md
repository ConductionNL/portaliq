# build-guards Specification

## Purpose
Checks that run at build time and stop a release that would break the site in a way no unit test sees, such as a second copy of a shared library in the bundle.

## Requirements

### Requirement: The Dexie guard must count only real Dexie copies

The Dexie singleton guard MUST count a built chunk as a Dexie copy only when it holds Dexie's error
as thrown ("Two different versions of Dexie loaded in the same app: "). A chunk that only quotes
the phrase in prose MUST NOT count. Two versions, a version the lockfile does not resolve, and a
copy without a readable version MUST still fail.

#### Scenario: A development bundle with the library's comment
@e2e exclude Fixture runs of the real script in node: tests/site-look/dexie-guard.spec.mjs
- GIVEN `js/` from `NODE_ENV=development webpack --config webpack.site.js`, where one chunk quotes the error in a comment and one chunk embeds Dexie
- WHEN `npm run check:dexie` runs
- THEN it exits 0 and reports one Dexie version across one chunk

#### Scenario: Two versions
@e2e exclude Fixture run in node: tests/site-look/dexie-guard.spec.mjs
- GIVEN two chunks embedding different Dexie versions
- WHEN the guard runs
- THEN it exits 1
