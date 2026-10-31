# Tasks: signin-eherkenning-eidas-and-the-token-fragment

- [ ] **T01**: `tests/Unit/Service/OidcClaimMapperServiceTest.php`: pin the eHerkenning preset (identity type `eherkenning`, audience `supplier`) and the eIDAS preset (identity type `eidas`, audience `client`, subject reference derived) (REQ-SEE-001, REQ-SEE-002). Verification: both tests fail when a preset's audience is changed.
- [ ] **T02**: `tests/Unit/Service/Signin/BrokerLoginTest.php`: a broker envelope for provider `eherkenning` mints a `supplier` session and one for `eidas` a `client` session; an account that names its own audience keeps it (REQ-SEE-001, REQ-SEE-002).
- [ ] **T03**: Controller tests for the OIDC callback, the broker callback, the Nextcloud way in and the example resident sign-in: the success redirect carries the bearer only after `#token=` (REQ-SEE-003). Verification: a test per route asserts no `token` query parameter.
- [ ] **T04**: Node test for `consumeOidcCallbackFragment` in `src/shared/portalApi.js` next to the existing `adoptSessionToken` case in `tests/site-auth.spec.mjs`: the fragment is adopted once and the address loses it (REQ-SEE-003).
- [ ] **T05**: Add `@spec` tags naming REQ-SEE-001..003 to `OidcClaimMapperService::applyPreset`, `BrokerLogin::redeem`, `adoptSessionToken` and `consumeOidcCallbackFragment`.
- [ ] **T06**: Live check on a broker-routed organisation once integriq's IdP adapter answers: eHerkenning and eIDAS sign-ins reach the site signed in, with the address clean; screenshots against the Inloggen board in the build PR. Not runnable until integriq's vendor half lands.
- [ ] **T07**: `openspec validate signin-eherkenning-eidas-and-the-token-fragment --strict`.
