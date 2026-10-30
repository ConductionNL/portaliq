# Tasks: collection-intro

- [x] **T1**: the normaliser keeps a collection's `intro`, trimmed, only when it is a non-empty string (`CollectionIntroKey`), and the register schema documents it
  - PHPUnit `CollectionIntroKeyTest`
- [x] **T2**: the site's collection renderer shows the intro above the collection's table, and "My cases" shows the intros of the cases collections, both as plain text (`src/shared/collectionIntro.js`)
  - `node --test tests/collection-intro.spec.mjs`
