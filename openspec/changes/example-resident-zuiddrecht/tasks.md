# Tasks: the Zuiddrecht example resident

- [x] 1. Declaration `lib/Settings/sites/residents/zuiddrecht.json` from the design files and dossiq's shipped case types.
- [x] 2. `ExampleResidentCatalogue`, `ExampleResidentValues`, `ExampleResidentStore`, `ExampleResidentObjects`, `ExampleResidentWayIn`, `ExampleResidentUser`, `ExampleResidentRecord`, `ExampleResidentInstaller`, `ExampleResidentRemover`.
- [x] 3. `occ portaliq:example-resident:install` and `:remove`, registered in `appinfo/info.xml`.
- [x] 4. Tests: `tests/example-resident.spec.mjs` in `check:specs`, PHPUnit for the installer, remover, values, catalogue and commands.
- [x] 5. Documentation in `docs/Installation/example-site-zuiddrecht.md`: the resident, who can sign in, why the test sign-in stays off, removal.
- [ ] 6. Live check on a fresh instance (coordinator). — not run: needs a live instance
