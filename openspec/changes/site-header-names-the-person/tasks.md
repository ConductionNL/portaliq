# Tasks: site-header-names-the-person

- [x] **T1**: The session answer carries the account's display name, never the reference (`SessionController::index`, `displayNameOf`).
  - `SessionControllerTest::testIndexNamesThePersonNeverTheReference`
- [x] **T2**: The header line names the person, else reads "Logged in"; English and Dutch strings.
  - `node --test tests/site-signed-in-shell.spec.mjs` ("the header says who is signed in, in the site language")
  - Live: Fatima Hulstkamp's header reads "Ingelogd als Fatima Hulstkamp" on the primary-school instance
