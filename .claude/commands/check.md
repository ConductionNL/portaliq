---
description: Run the portaliq quality suite (PHP strict gate, ESLint, Stylelint, spec checks)
---

Run the quality suite from the repository root and report each failure with its file and line.

```bash
composer check:strict      # lint, phpcs, phpmd, psalm, phpstan, PHPUnit
npm run lint               # ESLint
npm run stylelint          # Stylelint
npm run format             # Prettier check
npm run check:specs        # JSON, manifest and register validation plus the node --test specs
```

`check:specs` is a chain joined with `&&`: it stops at the first failing spec, so fix that one and run it again. Fix what the change touched; do not add baseline entries or suppressions to get green. Auto-fixers: `composer cs:fix`, `composer phpcs:fix`, `npm run lint-fix`.
