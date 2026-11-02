## Why

On the school portals an action that needs input does not work from the site, while the same
call works through the API (portal-proof run 3, 9 October 2026):

- Milan logs his BPV hours: the site sends `hoursSubmitted: "16"`, OpenRegister refuses a string
  for a number, and the site shows "Dit is nu niet beschikbaar".
- Petra's "Uren goedkeuren", Linda's "Medewerkers inschrijven" and "Geboortedatum invullen", and
  the guardian's "Afwezig melden" on the overview open no form: the button posts an empty body
  and the app answers 422 or 403.
- Milan's "Nu invullen" on a work process sends `PATCH {}` at once, so nothing changes.
- Every refusal reads as the generic "Dit is nu niet beschikbaar", also when the server knows
  which field is wrong.

The causes are in portaliq, not in learniq's declarations: learniq declares `fields`,
`fieldConfigs` and `optionsProviders` on each of these actions.

1. `formBody` sent every value as a string, and the site never knew a field's value type.
2. `ActionBlock` drew a form only for an `action` block on a `create` or `update` action. An
   endpoint action (`endpoint-forward`), and any `cta` (the greeting's button is one), became
   `ActionButton`, which posts `{}`. An update row action ran `collectionLoader.transition`,
   which also sends `{}`.
3. `ActionButton` and the form mapped a refusal to one generic text, and a create that
   OpenRegister refused answered 502 `write_failed` without naming the field.

## What Changes

- The server adds `valueType` (`integer`, `number`, `boolean`) to a field's config from its
  schema type (`SchemaInputHintNormaliser`). The site sends each value in that type (a count
  stepper as an integer, a number input as a number, a decimal comma read as a point) and leaves
  an empty number field out. The form checks a number field before it sends.
- An action that needs input (`asksInput`: a field that is not stamped by the server, set by the
  action or hidden) opens its form before anything is sent:
  - an `action` block on an endpoint action draws the form and sends it with `forwardAction`;
  - a `cta` (and the greeting's button) on a create or endpoint action is a button that opens the
    form;
  - an update row action with fields opens `RowActionForm` below the table, starting from the
    row's values, and PATCHes only the answers.
  An action without fields to fill in keeps its one button or its transition.
- A refused value names its field: `WriteRefusal` reads the field and the kind of value from
  OpenRegister's refusal and answers 422 `{error: 'invalid', invalid: {field: kind}}` on a create
  and an update (never the store's own text, only fields the portal wrote). The form shows a
  plain message on the field ("Uren die je goedkeurt: vul een getal in, bijvoorbeeld 8 of 7,5."),
  and a refusal without a field reads by status: "Niet alles is goed ingevuld. Kijk uw antwoorden
  na." for 400 and 422.

## What learniq should change (not done here)

Nothing is needed for the four actions to work. Two changes make them better:

- `approveHourWeek` (TrainerSitePages): add `'schema' => 'bpv-hour-week'`, the way
  `enrolEmployees` declares its schema "only so portaliq shapes the inputs". `hoursApproved`
  then gets a number input and is sent as a number.
- The endpoint refusals (`PortalOutcome` 422 `{error: 'incomplete'}` in PortalHourWeekApproval,
  PortalEmployerBookings, PortalWerkprocesAssessment) should name the fields:
  `errors: {hoursApproved: ''}` (the site then says "<label> is verplicht") or
  `invalid: {birthDate: 'date'}`.

## Impact

- Site: `forms.js`, `SchemaForm.vue`, `ActionBlock.vue`, `RowActionDialog.vue`, new
  `RowActionForm.vue`, `ContributionPage.vue` (`onRowAction`), `src/shared/actionInput.js`,
  `portalApi.js` and `fileFieldSubmit.js` (keep `status` and `invalid`).
- Server: `SchemaInputHintNormaliser` (valueType), `PortalObjectWriter::lastFailure()`, new
  `WriteRefusal`, `ContributionController::create` and `writeScoped`.
- Contract: a field config may carry `valueType`; a refused write may answer 422 with `invalid`.
  A client that ignores both behaves as before.
