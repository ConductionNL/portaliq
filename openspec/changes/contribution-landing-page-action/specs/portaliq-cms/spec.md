# portaliq-cms Specification (delta)

**OpenSpec change**: [contribution-landing-page-action](../../changes/contribution-landing-page-action/)

## ADDED Requirements

### Requirement: A page may carry a hero image reference

The `page` schema MUST carry an optional `heroImage` property — a string
reference/URL to an image, rendered by a `hero`-keyed widget when present.
This is additive: a page with no `heroImage` behaves exactly as before this
change.

#### Scenario: A page created with a hero image renders it

- GIVEN a `page` object whose `heroImage` is set
- WHEN the page is served
- THEN the hero widget's rendered props include the image reference
- @e2e exclude schema/render-plumbing contract — no distinct portaliq UI flow ships changed hero rendering in this change; covered by `LandingPageProvisioningServiceTest` asserting the field is written through unchanged

### Requirement: A public page may embed a lead-capture form widget

The public site renderer MUST support a `form`-keyed widget
(`src/site/components/FormBlock.vue`, registered locally in
`WidgetGrid.vue`'s `PUBLIC_WIDGETS` map — no change to the shared
`@conduction/nextcloud-vue` library) that renders the fields, `submitLabel`
and `consentText` of a bound `form` object and submits through the existing
anonymous contribution-create endpoint. An unrecognised `widgetKey` (an older
deployed bundle, before this change ships) MUST continue to degrade to the
existing inert placeholder — this addition changes nothing about that
fallback.

#### Scenario: A landing page renders its bound form and accepts a submission

- GIVEN a `page` whose grid body includes a `form`-keyed widget bound to an active `form` object's id
- WHEN a visitor loads the page
- THEN the form's declared fields, `submitLabel`, and `consentText` render
- AND submitting the form with valid values succeeds without a portal session
- @e2e site-form-submission.spec.ts — "a visitor submits the landing page form and it is recorded"
