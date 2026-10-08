# Tasks: password-reset-from-the-sign-in-page

- [ ] **T01**: `signInRoutes()` returns `lostPasswordUrl` for a portal offering `nextcloud`, built with `IURLGenerator` and a `redirect_url` to the account route; PHPUnit present and absent (REQ-PWR-001)
- [ ] **T02**: `src/site/components/WaysIn.vue` shows "Wachtwoord vergeten" from `lostPasswordUrl`, per the Inloggen board; strings in Dutch and English (REQ-PWR-001)
- [ ] **T03**: node test for the link's presence per portal; Playwright: link opens Nextcloud's reset page
- [ ] **T04**: Live check against the Inloggen board; screenshot in the build PR
