## Why

The school boards' own area (De Wilgenboom MijnMenu, MijnOverzicht) has no "Zaken en taken"
group. The proof (06 Oct, item 11) showed it on every school portal: "Mijn taken" and "Toegang tot
zaken". Portaliq builds that group itself from shell sections that are on for every signed-in
resident (`tasks` whenever the task seam is there, `access` for every session), and the portal's
menu layout (`residentMenu.groups`) puts any item it does not name back under its own group, so a
portal could not leave them out. learniq's FIX-L lane confirmed it declares nothing of the kind.

## What Changes

- Portal schema (0.13.0, register 0.70.0): `residentMenu.leaveOut`, a list of item names (a
  section of the own area such as `cases`, `tasks`, `access`, or a contributed page as
  `app:page`) the portal leaves out of the menu. The pages stay reachable by their address.
- `PortalShell` serves it, well-formed names only, at most 20, never `overview`.
- `residentMenuGroups()` drops those items before the layout is applied, and a group that is empty
  then; `App.vue` passes the list in both places it builds the menu.
- Schema strings in English and Dutch; tests: `PortalShellTest`
  (`testTheProjectionServesTheItemsLeftOut`), `tests/site-look/resident-menu-leave-out.spec.mjs`.

## For learniq (lane FIX-L)

Each school portal declares `"residentMenu": {"leaveOut": ["cases", "tasks", "access"]}`.

## Impact

- No change for a portal that declares nothing (Zuiddrecht keeps its menu).
