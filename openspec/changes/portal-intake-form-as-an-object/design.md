# Design: portal-intake-form-as-an-object

## D1. Portaliq renders a form it does not own

The form definition is `registrationForm`, published by buildiq's
`forms-per-case-type` and served by the `buildiq-registration-form` leaf.
A portal form page stores a binding, not a copy: the type tuple, an
audience and an optional form name. The portal asks the leaf at render
time and gets back the fields, the order and `presets[]`.

A copy would go stale the first time a form is edited. A binding cannot.

## D2. D16 decides where each half lives, so neither lands twice

Ruben's answer: the portal flag lives on the field, the form owns order
and channel. Two consequences for this change.

- Portaliq never asks whether a citizen may see a field. The field says
  so, and the leaf returns what the audience may have.
- Portaliq never invents an order. The form carries it.

Both are refusals, and both are worth writing down, because the cheap
version of this change is a portal that keeps its own field list.

## D3. Intake settings belong to the form, not to the portal page

Address lookup, prefill from earlier cases, the challenge and the
confirmation text are properties of the intake, so they travel with the
form binding. A municipality that publishes the same form on two portals
gets the same behaviour on both. The portal page adds placement, not
policy.

## D4. An external start form is an intake kind, not a special case

`intakeKind` on the binding is either `hosted` or `external`. An external
binding carries a URL and renders a start card that names the destination
before the visitor leaves. It is how a municipality using Open
Formulieren keeps its forms and still appears in the catalogue, which is
most of them.

The portal does not proxy the external form. Proxying means we own the
privacy statement of somebody else's page.

## D5. Prefill is identity, and nothing else

The applicant block is filled from the portal identity's own claims: the
name, the address and the contact details the identity already carries.
A visitor with no session gets an empty block and no hint that one
existed. Prefill from another person's data is the failure this design
exists to make impossible.

## D6. Validate before creating, and say which field

The submission is validated against the form's schema before a
contribution create is attempted. The citizen sees a field error. The
alternative, letting OpenRegister refuse on write, gives the citizen a
failed submission and the case app a half-written object.

## D7. Asynchronous, with a receipt that is not a promise

A submission is accepted, queued and acknowledged with a reference. The
receipt says the request was received, not that a case exists. When the
create fails, the reference resolves to a page that says so and names
what to do. A receipt that implies a case, followed by no case, is worse
than a slow page.

## D8. The entry point is content

Pages, topics and layouts are `portaliq-cms` objects arranged by an
editor. The catalogue entries come from opencatalogi's published
catalogue, not from a list kept in the portal. One gemeentelijk loket
over twenty case types, editable by a redacteur rather than a developer,
which is what the lane found at Jira Service Management and what we do
not have.

## Risks

- **The leaf is unavailable at render time.** The page renders the
  request, states that the form cannot be loaded, and keeps the external
  and catalogue entries working. It does not fall back to a cached field
  list, because a stale form is a wrong form.
- **A form published for the wrong audience.** The binding names the
  audience and the admin surface shows which form it resolves to today,
  with its name, so the mistake is visible before a citizen finds it.
- **Async hides a failing create.** Failed submissions are listed for the
  administrator with their reference and their reason, and the citizen's
  reference page reads the same state.
