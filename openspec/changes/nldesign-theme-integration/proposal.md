---
kind: code
---

# Proposal: nldesign-theme-integration

## Summary

Adopt the theme capabilities the `nldesign` app already owns — token-set
**generation**, **adoption** and **sharing** — instead of the single static
stylesheet link Portaliq uses today. Portaliq stopped shipping design tokens
when `css/themes/` was deleted and the VNG set moved to `nldesign`
(ConductionNL/nldesign#352); this change completes that move by consuming the
app's *interfaces* rather than only its files.

## Motivation

Portaliq's entire relationship with theming is one line: `portal.theme` is a
string, and `PortalThemeResolver` turns it into
`nldesign/css/tokens/<theme>.css` if that file exists. Everything `nldesign`
has built around token sets is unreachable from a portal.

Measured on the `nldesign` app as it stands:

| capability | where it lives | reachable from a portal today |
| --- | --- | --- |
| token-set catalogue (44 sets) | `TokenSetService`, `CatalogController` (`#[NoAdminRequired]`) | no |
| custom token sets + validation | `CustomTokenSetService`, `CustomTokenSetController` | no |
| dark-mode variant generation | `DarkPaletteService` (1096 lines) | no |
| preview rendering | `TokenSetPreviewService`, `PreviewController` | no |
| contrast evaluation | `ContrastController` | no |
| WCAG compliance reporting | `ComplianceReportService` (792 lines) | no |
| shipped-set auditing | `ShippedTokenSetAuditService` | no |
| fonts | `FontService` | no |
| **sharing** across instances | `NlDesignThemeShareableConfigType` + OpenRegister config types | no |

Four consequences, all live today:

1. **A portal cannot offer a theme picker.** The catalogue endpoint is already
   `#[NoAdminRequired]` and read-only; nothing consumes it.
2. **A portal has no dark mode.** `DarkPaletteService` generates
   `css/tokens/dark/<id>.css` and `CssInjectionService` emits it for the
   Nextcloud UI. The portal template links neither.
3. **A portal's theme is never checked for contrast.** This work alone produced
   three defects where text was rendered against a background it could not see
   — footer links at ratio 1.06, a hero heading dark-on-dark, a search label
   white on light blue. `ContrastController` and `ComplianceReportService`
   exist and were not consulted once.
4. **A theme cannot be shared to a portal.** `NlDesignThemeShareableConfigType`
   already registers themes as an OpenRegister shareable config type, which is
   how the fleet moves configuration between instances. Portals are outside it.

The dependency is now hard either way: without `nldesign` installed a portal
renders unstyled. Having taken the dependency, we should take the capabilities.

## Goals

- A portal ADOPTS a token set from the catalogue, by id, validated against what
  is actually installed — not by a filename that may not exist.
- A portal serves the generated **dark variant** when one exists.
- A portal's theme is **contrast-checked** at adoption time, and the result is
  visible to whoever picks it.
- A theme SHARED through OpenRegister can be adopted by a portal on another
  instance without copying CSS between repositories.
- `nldesign` remains the single source: this change adds no tokens to Portaliq
  and no theming mechanism of its own.

## Non-goals

- Authoring or editing token sets inside Portaliq. That is `nldesign`'s admin
  surface and duplicating it is the mistake this change exists to stop.
- Per-page or per-visitor theming. A portal has one theme.
- Replacing the `--utrecht-*` / `--tilburg-*` component-token layer.

## Risks

- **A portal is a public origin.** Any endpoint it consumes must be anonymous-
  safe. The catalogue is already `#[NoAdminRequired]`, but *read-only for a
  logged-in user* and *safe for the anonymous internet* are different claims,
  and the second one has to be established rather than assumed.
- **Adoption must fail closed.** An unresolvable theme must render UNSTYLED,
  never fall back to another municipality's colours — a portal wearing the
  wrong brand looks correct in every screenshot.
- **Sharing crosses a tenancy boundary.** A shared theme is configuration from
  elsewhere; it must not be able to inject CSS. `CustomTokenSetValidator` is
  the existing gate and must be on this path too.
