---
description: Run the portaliq unit tests (PHPUnit and the node --test specs)
---

Run from the repository root:

```bash
composer test:unit       # PHPUnit, tests/Unit (phpunit.xml)
npm run check:specs      # frontend specs in tests/*.spec.mjs
```

To run one PHP test file: `./vendor/bin/phpunit tests/Unit/<path>Test.php`.
To run one frontend spec: `node --test tests/<name>.spec.mjs`, or its `npm run check:<name>` script.

$ARGUMENTS
