# Design: operate-show-per-case-type

Read at portaliq `development` `eeda3fa`.

## What exists

- `lib/Service/PortalCaseListReader.php:84-128` `listCases()` gathers rows
  from every contribution collection of kind `cases` (`KIND`, line 50) and
  tags each with its source. `listMandatedCases()` (line 148) reads the case
  type of each row from `caseTypeField`, default `caseType` (line 311).
- `lib/Controller/ContributionController.php:584-632` `object()` reads one
  row of a collection with the collection's scope.
- `lib/Service/Intake/PortalFormBindingResolver.php:141-180`
  `declaredCaseTypes()` returns `[register, schema, typeId]` per published
  binding of a portal.
- `portalCaseType` in `lib/Settings/portaliq_register.json` carries the case
  app's portal declarations. It has no show or hide flag, and it is the case
  app's, not the portal administrator's.
- `lib/Service/PortalObjectReader.php:273-285` `scopedFilters()` applies a
  collection's declared `filter`, which is the case app's code.

## D1. The choice lives on the portal

`portal` gains `hiddenCaseTypes`: an array of `{register, schema, typeId,
label}`. Empty by default, so every portal behaves as today. It is the
portal's, because the same case app may serve two portals of one
organisation that want different things shown.

## D2. One predicate, three enforcement points

`lib/Service/CaseTypeVisibility.php::isHidden(array $portal, string $typeId)`
matches on `typeId`. It is called:

1. In `PortalCaseListReader::listCases()` and `listMandatedCases()`: a row
   whose `caseTypeField` value is hidden is dropped.
2. In `ContributionController::object()` and `collection()` for a collection
   of kind `cases`: a hidden type's row is answered with the same 404 as a
   row that does not exist, and dropped from a list.
3. In `PortalFormBindingResolver::render()`: a binding whose `typeId` is
   hidden resolves to no form with reason `hiddenCaseType`, and the intake
   catalogue leaves out its entry.

The inbox is not filtered. A message the organisation sent stays delivered;
hiding a case type is about what the portal offers, not about recalling
correspondence.

## D3. The screen

`src/manifest.json` gains `PortalCaseTypes` (`/portals/:id/case-types`,
custom component `PortalCaseTypeVisibility`), linked from `PortalDetail`.
It calls `GET /api/portals/{slug}/case-types`, which returns the union of:

- published bindings' case types (`declaredCaseTypes()`), labelled from the
  case type object through `CaseTypeReader::readCaseType()`;
- for each `cases` collection that declares `caseTypeSource`, the objects of
  that schema with their `labelField`;
- the case types already in `hiddenCaseTypes`, so a hidden type never drops
  off the list.

Each row has a switch "Show in this portal". Saving calls
`PUT /api/portals/{slug}/case-types` with the hidden list; both routes are
admin-only, like `SettingsController::update()`.

## Risks

- **A case type known only from cases.** Without `caseTypeSource` or a form,
  the screen cannot name it. The sibling half closes that; until then an
  administrator sees what the portal can name.
- **Hiding a type with open requests.** The resident loses the page for
  that request. The screen warns: "Residents with a case of this type will
  no longer see it here."

## What this deliberately does not do

- No per-resident exception.
- No change to the case app's own filter or declarations.
