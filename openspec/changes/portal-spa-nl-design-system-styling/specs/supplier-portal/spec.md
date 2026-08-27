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

#### Scenario: Disabled login buttons explain themselves
- **GIVEN** eHerkenning/DigiD login is not yet wired for this tenant
- **WHEN** a screen-reader user reaches the disabled login button
- **THEN** an `aria-describedby`-linked explanation is read alongside the
  button label

#### Scenario: Create-action input never uses a native prompt
- **GIVEN** a subject clicks a `type: create` action button
- **WHEN** the portal collects the action's declared fields
- **THEN** it renders a labelled, keyboard-operable inline form — never
  `window.prompt()`

## Notes

- **@e2e**: automated axe-core scan on login + dashboard views covers the
  "theme actually renders" and "disabled login buttons explain themselves"
  scenarios (tasks.md 4.1).
- The keyboard-only walkthrough (tasks.md 4.2) is a manual pass, not a CI
  assertion.
  @e2e exclude manual keyboard walkthrough is not a single automatable assertion

