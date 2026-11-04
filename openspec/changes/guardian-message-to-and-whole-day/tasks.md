# Tasks: guardian-message-to-and-whole-day

- [x] **T1** `CtaBlockNormaliser` keeps a plain-field `messageTo`; register 0.108.0, portalPage 0.10.0. Tests: `PortalBlockResolverTest::testACtaKeepsWhomItsMessageGoesTo`, `PortalPageSchemaTest`, `PortaliqRegisterConfigTest`.
- [x] **T2** Site: a cta with `messageTo` keeps the record and staff member; the messages page opens its form with that contact chosen. Test: `tests/site-messages-per-record.spec.mjs` ("Bericht aan meester Daan" opens the form with meester Daan chosen).
- [x] **T3** Site: today's named day reads ", de hele dag" after its date. Test: `tests/site-form-widgets.spec.mjs`.
- [ ] **T4** learniq: `messageTo` on the child page's message cta. Tracked in learniq, not built here.
