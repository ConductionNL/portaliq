## Why

Board SkPubliek has, under the software catalogue, "Uw organisatie aanmelden": a
supplier or government organisation registers without an account, and an
administrator reviews it. The decision (23 Oct) is that this is a portaliq form
that saves into stackiq's organisation schema, with `registrationStatus: pending`
fixed on the server.

Portaliq already has that form: a `form` object becomes an anonymous create
action that writes straight into the destination the form names, with the
destination's `fixed` values set by the server over any answer (decision 179).
What is missing:

1. An example site cannot declare a form. Forms are only created by the
   `LandingPageRequestedEvent` an app dispatches, so the example site cannot ship
   the registration form with the page that shows it.
2. A select field shows its stored values. The organisation types are stored in
   English (`Supplier`, `Municipality`); a Dutch site needs Dutch words for them.

## What Changes

- An example site MAY declare `forms`: `form` objects keyed by their `externalReference`, installed for
  the site's portal before its pages. A page's `form` block names its form by
  `formKey`; the installer writes the installed form's id as `formId`. A form, like
  a page, can require an app.
- A form field MAY carry `optionLabels`, the words shown per stored option. The
  form block shows them and still sends the stored value.
- A landing-page form's campaign fields (`utmFirstTouch`, `utmLastTouch`,
  `referrer`) go only to a destination that declares them. Until now they went to
  every destination, and one that lacks them refuses the whole submission.
- The Zuiddrecht example site gets the registration form on `/softwarecatalogus`:
  organisation name, type, OIN, website, contact name and e-mail, saved into
  `stackiq` / `organization` with `registrationStatus: pending` fixed. Stackiq also
  stamps every anonymous organisation create as pending (stackiq change
  `organisation-registration-through-the-portal`), so the rule holds whichever
  way the create arrives.

## Out of scope

- The activation mail after an administrator approves the registration.
