# Tasks: portal-create-cross-refs

Kind: code. Unblocks dossiq `move-portals-to-portaliq` T7.

- [x] 1.1 `lib/Contribution/CrossRefConfigNormaliser.php`: the declaration,
  fail closed by dropping the ACTION (D-2).
  - `@spec openspec/changes/portal-create-cross-refs/specs/portal-contribution-contract/spec.md`
- [x] 1.2 `lib/Contribution/ActionConfigNormaliser.php` runs it, and builds it
  when it was not injected.
- [x] 1.3 `lib/Service/PortalCrossRefGuard.php`: the scoped read per declared
  reference (D-1, D-3).
- [x] 2.1 `lib/Controller/ContributionController.php`: refuse create and
  update with 403 `cross_ref_refused` before any write.
- [x] 3.1 Unit tests for both halves; `openspec validate portal-create-cross-refs
  --strict`.
