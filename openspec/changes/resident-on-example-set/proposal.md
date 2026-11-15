## Why

The Zuiddrecht demo is one municipality across many apps. Its people live once, in
OpenRegister's example-set cast, and every app adds its part of the story with
`occ openregister:example-set:install zuiddrecht` (OpenRegister change `example-sets`,
ConductionNL/openregister#4603). portaliq's example resident, Sanne de Vries, was made by its own
command with its own account, so the demo had two installers claiming one person. Ruben decided
on 13 Oct 2026 that portaliq's resident moves onto the cast.

## What Changes

- **A fragment of the example set**, `lib/Settings/example-sets/zuiddrecht.json` (`after:
  ["dossiq"]`). It writes Sanne's portal account, her five dossiq cases, the open question and the
  four messages, and adds the `nextcloud` sign-in mode with its card to the portal as a change the
  set records and takes off again. The ten case, question and message objects are the old
  declaration's, word for word, with `{{subject}}` replaced by `{{cast:sanne.uid}}`. The Woo
  journey and the resident boards depend on them.
- **The other cast members get portal accounts.** Henk, Linda and Fatima (citizen) and Jan-Willem and Mark
  (supplier), with mandates for Linda (her father Henk) and for Jan-Willem and Mark (their companies), so
  their dossiq cases show in Mijn Zuiddrecht. They sign in with their own account; one click stays Sanne's.
- **The demo sign-in reads the example set.** `exampleResident` in the fragment names the portal,
  the portal account object and the sign-in change. The one-click sign-in trusts only a portal
  account OpenRegister's install record holds for the set, and only when its account id is one the
  set created or adopted. Only `occ` writes that record, as it wrote the old one.
- **`occ portaliq:example-resident:install` retires**, with its installer, object writer, value
  filler, catalogue and declaration. portaliq has no deprecation convention for commands, so it
  goes now.
- **`occ portaliq:example-resident:remove` stays for one release**, so an instance with an old
  install can take it off before the set installs the resident again; otherwise she gets every case
  twice. It takes the portal from `--portal`, default the site id, and points at the loader.

## Capabilities

### Modified Capabilities

- `example-resident`: the resident comes from OpenRegister's example set.

## Impact

- Depends on ConductionNL/openregister#4603 (the loader, `changes`, `jsonFields`, and the record
  read by `ExampleSetResidents`). Without it, no example resident is installed and the demo
  sign-in offers nobody, which is safe.
- `docs/Installation/example-site-zuiddrecht.md` describes the new route and the move.
