## 1. The fragment

- [x] 1.1 `lib/Settings/example-sets/zuiddrecht.json`: portal account, the old ten objects with `{{cast:sanne.uid}}`, the sign-in change, `after: ["dossiq"]`, `exampleResident`
- [x] 1.2 `tests/example-resident.spec.mjs` checks the fragment: placeholders, the account against `portalAccount`, the card's house rules, the five cases

- [x] 1.3 Portal accounts for henk, linda, fatima (citizen), janwillem and mark (supplier); mandates Linda for Henk, Jan-Willem and Mark for their companies; the node test checks them against `portalAccount` and `portalMandate`

## 2. The demo sign-in

- [x] 2.1 `ExampleSetResidents`: the resident of a portal from OpenRegister's example-set record, refusing an account the set did not make or adopt
- [x] 2.2 `ExampleResidentSignIn` and `PortalRuntimeConfigResolver` read it; controller, resolver and sign-in tests follow

## 3. Retire the install

- [x] 3.1 Remove `ExampleResidentInstall`, `ExampleResidentInstaller`, `ExampleResidentObjects`, `ExampleResidentValues`, `ExampleResidentCatalogue`, `lib/Settings/sites/residents/zuiddrecht.json` and their tests; drop the command from `appinfo/info.xml`
- [x] 3.2 Keep `example-resident:remove` one release: `--portal`, points at the loader
- [x] 3.3 Update `docs/Installation/example-site-zuiddrecht.md`

## 4. Verification

- [x] 4.1 Unit and node tests of the touched classes
- [ ] 4.2 Live: install the set on an instance with portaliq, dossiq and OpenRegister #4603, sign in as Sanne with one click, remove the set (not done here)
