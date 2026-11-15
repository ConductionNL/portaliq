---
kind: code
---

# Proposal: dev-login-only-when-switched-on

## Why

The live test of the Zuiddrecht demo on 13 and 14 October 2026 found "Dev-login (test)" on the public sign-in page, signing any visitor in as "Voorbeeld Leverancier B.V." without a password. On that instance it was the explicit app flag `dev_login_enabled = yes`, set for the e2e suite, and the default is off. But the gate had a second key: system `debug` opened it too (`SessionController::isDevLoginEnabled()` and `PortalRuntimeConfigResolver::devLoginEnabled()`). A demo or tenant that turns `debug` on to read an error would put an open, passwordless door on a public page without anyone setting anything for portaliq.

## What changes

- **The test sign-in opens only on `dev_login_enabled = yes`.** System `debug` no longer opens it, and the site no longer offers its button in debug mode. The one-click example resident already followed this rule.
- CI is unaffected: `ci-seed.sh` sets the dedicated flag, not `debug` (see `.github/workflows/code-quality.yml`).
- README and the Zuiddrecht install guide say so.

## Out of scope

- Turning the flag off on a test instance that set it on purpose.
