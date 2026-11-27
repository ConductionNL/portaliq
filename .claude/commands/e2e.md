---
description: Run the portaliq Playwright end-to-end suite
---

The suite has no default target, on purpose (see the header of `playwright.config.ts`). Ask which instance to use if `PLAYWRIGHT_BASE_URL` is not set; do not point it at a shared development container.

```bash
npx playwright install chromium                         # one-time browser download
PLAYWRIGHT_BASE_URL=<your instance> npm run test:e2e    # tests/e2e
```

$ARGUMENTS
