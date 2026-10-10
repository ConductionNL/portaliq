## Why

`npm run check:dexie` failed on a good development bundle set (school portal proof, 06 Oct, item
17): "embeds Dexie (sentinel found) but no version literal matched". The guard counts a chunk as a
Dexie copy when it contains "Two different versions of Dexie". A development build keeps comments,
and `@conduction/nextcloud-vue` explains in a comment why it loads Dexie lazily, quoting the error.
Every chunk that bundles that module then read as a Dexie copy without a version. Reproduced on
`development` c26e822: `js/portaliq-site-editor.js` holds only the comment.

## What Changes

- `scripts/check-single-dexie.js`: the sentinel is the error as Dexie throws it, with its colon
  ("... loaded in the same app: "), which a prose quote does not carry and minification keeps.
  The bundle directory may be set by `DEXIE_CHECK_JS_DIR`, so a test can run the real script on a
  fixture.
- `tests/site-look/dexie-guard.spec.mjs`: a comment-only chunk is no copy; a development and a
  production chunk read; two versions fail; a copy without a readable version still fails.

## Impact

- The guard, one environment variable, one test. Release builds and `postbuild` behave as before
  on a bundle set without the comment.
