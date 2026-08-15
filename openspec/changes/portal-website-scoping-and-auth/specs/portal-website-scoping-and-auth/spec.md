# portal-website-scoping-and-auth Delta: portal-website-scoping-and-auth

**Status**: in-progress
**Scope**: portaliq
**OpenSpec changes**:

- [portal-website-scoping-and-auth](../../)

## Purpose

Makes `website` the unit Portaliq serves: host-based site resolution, verified
custom domains, per-site presentation, per-site authentication with scoped
sessions, and a per-site CSP. Implements ADR-086 §§2, 8, 11 and supersedes the
unimplemented white-label requirement in `supplier-portal`. Related: ADR-005
(fail-closed), ADR-046 (contribution contract), ADR-082 (throttling).

## ADDED Requirements

### Requirement: A request MUST resolve to exactly one website, or to none

Portaliq SHALL resolve the serving `website` from the request host, taken from
the trusted proxy configuration. An unresolved host SHALL return 404. There
SHALL be no default, first or fallback website.

#### Scenario: An unknown host returns 404 and reveals nothing

- **GIVEN** a request whose host matches no website
- **THEN** the response is 404
- **AND** no site's name, theme, logo or content appears in it

#### Scenario: A client-supplied host header does not select a site

- **GIVEN** a request whose `Host` header is forged to another site's domain
  while the trusted proxy reports the real one
- **WHEN** the site is resolved
- **THEN** the trusted value decides

#### Scenario: Two sites on one installation stay separate

- **GIVEN** two websites each with a page at the same route
- **WHEN** each domain is requested
- **THEN** each returns its own page, and neither page is reachable from the
  other's domain by any request parameter

### Requirement: A custom domain MUST be verified before it serves

A domain SHALL become active only after Portaliq resolves a
`TXT _portaliq-verify.<domain>` record carrying that website's nonce. An
unverified domain SHALL behave exactly as an unknown host. Verification SHALL
be re-checked periodically, and a removed record SHALL eventually unbind.

#### Scenario: DNS pointing at Portaliq is not enough

- **GIVEN** a domain resolving to Portaliq with no verification record
- **WHEN** it is requested
- **THEN** it returns 404

#### Scenario: Verification succeeds when the record is present

- **GIVEN** the correct TXT record published
- **WHEN** verification runs
- **THEN** the domain becomes active
- **AND** this positive case is tested alongside the negative one, so a
  permanently-failing verifier cannot pass for a working one

#### Scenario: A tenant cannot bind a domain it does not control

- **GIVEN** a website adding a domain owned by someone else
- **WHEN** verification runs
- **THEN** it fails and the binding stays inactive

### Requirement: Presentation MUST resolve per website at runtime

The portal template SHALL be rendered with the resolved website's name, logo,
themiq theme reference, locales and feature flags, exposed to the client as
runtime configuration. A hard-coded default SHALL NOT be served.

#### Scenario: Two websites render with different identity

- **GIVEN** two websites with different names and themes
- **WHEN** each is requested
- **THEN** each response carries its own name and theme reference
- **AND** neither carries the literal fallback the portal shipped before this
  change

#### Scenario: A website with no theme is reported, not defaulted

- **GIVEN** a website whose theme reference does not resolve
- **WHEN** it is served
- **THEN** the failure names the missing theme rather than rendering unthemed

### Requirement: Each website MUST declare its authentication, failing closed

A website SHALL declare one or more of `public`, `nextcloud`, `local`, `oidc`,
`digid`, `eherkenning`, `eidas`, with provider configuration for `oidc` and an
optional `minTrust` for the government modes. Absent or unparseable
configuration SHALL fail closed to `public` read-only.

#### Scenario: A malformed configuration grants no session and no write

- **GIVEN** a website with malformed authentication configuration
- **WHEN** a visitor attempts to sign in
- **THEN** no session is issued and no write is permitted
- **AND** anonymous read of published content still works

#### Scenario: An OIDC site accepts only its configured provider

- **GIVEN** a website configured with one OIDC provider
- **WHEN** an assertion from a different provider is presented
- **THEN** it is refused

#### Scenario: A government mode enforces its trust level

- **GIVEN** a website declaring `minTrust: substantial`
- **WHEN** a session below that level is presented
- **THEN** the protected surface is refused

### Requirement: A session MUST be scoped to the website that minted it

A session SHALL be valid only on the website that issued it.

#### Scenario: A session does not cross sites

- **GIVEN** a session minted for website A
- **WHEN** it is presented to website B on the same installation
- **THEN** it is not accepted
- **AND** the refusal is indistinguishable from an invalid session — it does
  not reveal that the session is valid elsewhere

### Requirement: Frame-ancestors MUST be derived per website

The portal's `Content-Security-Policy: frame-ancestors` SHALL be built from the
serving website's configuration. A wildcard SHALL NOT be emitted.

#### Scenario: A site with no declared embedders forbids framing

- **GIVEN** a website declaring no permitted embedders
- **WHEN** its page is served
- **THEN** `frame-ancestors` permits none

#### Scenario: A declared embedder is permitted, and only that one

- **GIVEN** a website permitting one origin
- **WHEN** its page is served
- **THEN** `frame-ancestors` names that origin and no other

#### Scenario: The wildcard is gone

- **GIVEN** any served portal response
- **WHEN** its headers are inspected
- **THEN** `frame-ancestors` is never `*`

### Requirement: A contribution MUST target a website without changing the contract

A leaf app's `PortalContributionProvider` SHALL continue to satisfy ADR-046
unchanged. A contribution SHALL name the website it appears on, and SHALL NOT
carry site identity, navigation, theme or authentication.

#### Scenario: An existing provider keeps working

- **GIVEN** procest's, pipelinq's or shillinq's existing provider
- **WHEN** the aggregate is built for a website it targets
- **THEN** its collections and actions appear, with no provider signature
  changed

#### Scenario: A contribution does not leak across sites

- **GIVEN** a contribution targeting website A
- **WHEN** website B's manifest is requested
- **THEN** it does not appear
