# 0001. External sign-in is a generic OIDC relying party behind an identity broker

- Status: accepted
- Source: [`openspec/changes/archive/2026-07-24-portal-oidc-broker-login/design.md`](../../openspec/changes/archive/2026-07-24-portal-oidc-broker-login/design.md),
  README section "OIDC broker login"

## Context

Portal visitors sign in with DigiD, eHerkenning or eIDAS. A direct integration
with those schemes means SAML, PKIo certificates and Logius metadata management.
An identity broker exposes the same schemes as plain OIDC and keeps that burden
on its own side. The portal already had an in-process session edge
(`PortalSessionService`, `PortalJwtService`, fail-closed middleware).

## Decision

Portaliq implements one generic, broker-agnostic OIDC relying party, in process
(not as an ExApp). `digid`, `eherkenning`, `eidas` and `generic` are provider
presets over that relying party. Each organisation configures its broker
(issuer, client id, scopes, claim map, LoA map). The client secret is stored in
its own sensitive app config entry and is never returned by an endpoint or
rendered in the SPA. A successful login mints the same portal session the rest
of the edge already understands. Portaliq is not an OIDC broker itself, and it
does not depend on OpenConnector: a broker fronted by OpenConnector later is just
another issuer in the per-organisation config.

## Consequences

- No new session format or trust vocabulary; the callback fills claims into the
  existing `portalAccount` and session path.
- Any conformant OIDC broker works; an organisation without a configured
  provider simply does not show that login button.
- Production DigiD use still needs the operational gates the design records
  (security assessment and DPIA); the code change does not deliver them.
