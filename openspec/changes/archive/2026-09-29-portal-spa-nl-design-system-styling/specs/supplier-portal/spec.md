---
status: proposed
---

# Spec: supplier-portal (NL Design System + accessibility)

## ADDED Requirements

### Requirement: The portal shell MUST use the NL Design System component set and meet WCAG 2.1 AA

The public portal SPA SHALL render its UI through `@utrecht/component-library-react`
primitives and NL Design System CSS tokens matching the resolved
`RUNTIME_CONFIG.theme`, not bare unstyled HTML elements. Loading states SHALL
be announced to assistive technology via `aria-live` regions. Disabled controls
SHALL carry a programmatically associated explanation. No native
`window.prompt()`/`window.alert()`/`window.confirm()` dialog SHALL be used for
any user input or confirmation.

#### Scenario: Theme actually renders
- **GIVEN** `RUNTIME_CONFIG.theme` is `'utrecht'`
- **WHEN** the portal shell mounts
- **THEN** the rendered DOM carries the Utrecht/NL-DS token classes and the
  shell is visually themed, not browser-default styling

#### Scenario: Loading is announced to screen readers
- **GIVEN** a supplier expands a collection
- **WHEN** the collection's objects are being fetched
- **THEN** the loading state is exposed via an `aria-live="polite"` /
  `role="status"` region, not only visual `…` text
- @e2e exclude a loading state lasts too briefly to catch reliably in a browser; pinned by tests/portal-live-regions.spec.mjs (every loading indicator is the Loading status region)

#### Scenario: Create-action input never uses a native prompt
- **GIVEN** a subject clicks a `type: create` action button
- **WHEN** the portal collects the action's declared fields
- **THEN** it renders a labelled, keyboard-operable inline form, never
  `window.prompt()`
- @e2e exclude a negative over the whole portal; pinned by tests/portal-live-regions.spec.mjs (no native prompt, alert or confirm anywhere in src/portal)

### Requirement: The signed-in portal MUST meet WCAG 2.2 AA

Every page a signed-in resident reaches from the portal navigation SHALL
pass an automated WCAG 2.2 AA check with no serious or critical violation,
and SHALL be operable by keyboard alone. A message the portal shows after an
action, a save or an error, SHALL be announced: an error as an alert, any
other message as a status.

#### Scenario: The signed-in portal has no serious WCAG 2.2 AA violation
- **GIVEN** a signed-in resident
- **WHEN** axe-core checks each page in the portal navigation against WCAG 2.2 AA
- **THEN** it reports no serious or critical violation

#### Scenario: A keyboard user reaches the inbox and its settings
- **GIVEN** a signed-in resident who uses no mouse
- **WHEN** they press Tab through the portal
- **THEN** they reach the inbox, open it with Enter, and open its notification settings with Enter

#### Scenario: A save or an error is announced
- **GIVEN** a resident who saves a change on their case
- **WHEN** the portal says it was saved, or that it could not be
- **THEN** the message sits in a status region, or an alert region for an error, so a screen reader reads it without the resident looking for it
- @e2e exclude pinned by tests/portal-live-regions.spec.mjs over every portal component

## Notes

- The axe-core scan (tasks.md 4.1) and the keyboard walk (tasks.md 4.2) are
  tests/e2e/portal-accessibility.spec.ts.
- The disabled DigiD/eHerkenning button this change first described no longer
  exists: portal-oidc-broker-login lists only the sign-in routes an
  organisation configured, so there is no disabled sign-in button to explain.

