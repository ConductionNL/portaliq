# Tasks: One click on a demo for the example resident

## 1. Spec
- [x] 1.1 Proposal and the spec delta.

## 2. Server
- [x] 2.1 `SessionController::exampleResident()` and its route, guarded by the explicit switch, the install record, the portal and the account; rate limited and throttled.
- [x] 2.2 The shell's sign-in config carries `exampleResident` while the switch is on.

## 3. Site
- [x] 3.1 The Voorbeeldinwoner card links to the one-click route and says "Alleen op deze demo".

## 4. Tests
- [x] 4.1 `SessionControllerTest`: off is 404, an unknown resident is 404, another portal is 404, on mints for the record's user only.
- [x] 4.2 `PortalRuntimeConfigResolverTest` and `tests/site-auth.spec.mjs`.

## 5. Docs
- [x] 5.1 `docs/Installation/example-site-zuiddrecht.md`: the switch and what it opens.
