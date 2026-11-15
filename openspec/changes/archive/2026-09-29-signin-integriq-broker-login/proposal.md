# Proposal: signin-integriq-broker-login

## Why

A resident signs in with DigiD, a business user with eHerkenning, and a visitor from another EU country with an eIDAS means. Portaliq does this today only when the organisation runs its own OIDC broker in front of the government login. The portaliq matrix says so for row `sig-digid`, in `built.note`:

> Real generic-OIDC broker login with a digid preset, not a direct Logius/SAML connector: it works only when the organisation fronts DigiD with its own OIDC-compliant broker and supplies issuer/clientId/clientSecret. No broker means no button (App.jsx:323-325 shows a hint instead).

Row `sig-eherkenning` has the same note ("Same broker-fronted model as DigiD: works when the organisation configures a real eHerkenning-fronting OIDC broker"), and so has row `cmp-sig-eidas` ("works only if the organisation configures a real eIDAS-node-fronting OIDC broker; no built-in eIDAS node connector").

The fleet decided where the government login lives: in integriq. Integriq's open change `idp-broker-envelope-runtime` (spec `digid-eherkenning-auth-adapter`) ships the signed subject envelope, the one-time code and the server-to-server exchange endpoint. Its proposal lists "Anything in portaliq" as out of scope. This change is portaliq's half: it consumes that exchange as a login route, so an organisation without its own OIDC broker can still offer DigiD, eHerkenning and eIDAS.

No demand row is attached to these five rows in `gap-rows.json`. The rows sit in the `signin` area, the portaliq matrix's core area. The competitor cells rated `yes`, quoted from `gap-rows.json`:

- `sig-digid`, Open Inwoner Platform: "src/open_inwoner/accounts/templates/registration/login.html:34 DigiD OIDC button (SAML fallback :47); src/open_inwoner/accounts/backends.py:163 DigiDOIDCBackend; src/open_inwoner/urls.py:153 SAML acs [reached on login page /accounts/login/; DigiD via OIDC broker or SAML (digid_eherkenning), admin configured]". No URL recorded.
- `sig-digid`, NL Portal: "frontend/packages/app/src/App.tsx:18 authenticationMethods person digid; backend/zgw/common-ground-authentication/src/main/kotlin/nl/nlportal/commonground/authentication/CommonGroundAuthenticationConverter.kt:48 BSN claim becomes BurgerAuthentication [...] DigiD is brokered by Keycloak; the portal reads the bsn claim after token exchange]". No URL recorded.
- `sig-digid`, xxllnc Zaken PIP: "backend/perl-api/lib/Zaaksysteem/Controller/Plugins/PIP.pm:431 login; backend/perl-api/root/tpl/zaak_v1/nl_NL/plugins/pip/login_type.tt:47 'Inloggen met' per citizen SAML IdP; backend/perl-api/lib/Zaaksysteem/SAML2.pm:819 Logius BSN from sector code [reached on /pip/login; SAML interface of type Logius (DigiD) must be configured]". No URL recorded.
- `sig-digid`, MijnOverheid: "https://mijn.overheid.nl/vragen/ requirements: 14 or older, a BSN, and 'DigiD of een ander Europees inlogmiddel' (eIDAS); no other login route named". URL: https://mijn.overheid.nl/vragen/
- `sig-eherkenning`, Open Inwoner Platform: "src/open_inwoner/accounts/templates/registration/login.html:151 eHerkenning OIDC button (SAML fallback :163); src/open_inwoner/accounts/backends.py:226 EHerkenningOIDCBackend [reached on login page Zakelijk tab, shown when SiteConfiguration.eherkenning_enabled; behind SiteConfiguration.eherkenning_enabled toggle]". No URL recorded.
- `sig-eherkenning`, NL Portal: "frontend/packages/app/src/App.tsx:19 company eherkenning; [...] BedrijfAuthentication.kt:21 kvk claim; [...] eHerkenning brokered by Keycloak; KvK data needs the haalcentraal-hr module]". No URL recorded.
- `sig-eherkenning`, xxllnc Zaken PIP: "backend/perl-api/root/tpl/zaak_v1/nl_NL/plugins/pip/login_type.tt:89 '/pip/login/bedrijf'; [...] SAML2.pm:1302 _get_eherkenning_identifier [reached on /pip/login 'Inloggen als organisatie'; eHerkenning via SAML interface type 'eHerkenning / KPN Lokale Overheid']". No URL recorded.
- `cmp-sig-eidas`, Open Inwoner Platform: "src/open_inwoner/accounts/templates/registration/login.html:61 eIDAS button; src/open_inwoner/accounts/eidas_urls.py:1; src/open_inwoner/accounts/backends.py:370 EIDASOIDCBackend (person BSN, pseudo id, company) [reached on login page, when the oidc-eidas client is enabled]". No URL recorded.
- `cmp-sig-eidas`, xxllnc Zaken PIP: "backend/perl-api/root/tpl/zaak_v1/nl_NL/plugins/pip/login_type.tt:41 eIDAS button; [...] PIP.pm:505 eIDAS nameid; [...] Form.pm:566 eIDAS prefill [reached on /pip/login; needs an eIDAS SAML interface]". No URL recorded.
- `cmp-sig-eidas`, MijnOverheid: "https://mijn.overheid.nl/vragen/ login with 'DigiD of een ander Europees inlogmiddel' (eIDAS)". URL: https://mijn.overheid.nl/vragen/
- `sib-decidiq-plt-02`, Open Inwoner Platform: "src/open_inwoner/cms/cases/views/status.py:669 CaseDocumentDownloadView behind CaseAccessMixin [...] case documents after DigiD or eHerkenning login, capped by a configurable confidentiality level; needs OpenZaak]". No URL recorded.
- `sib-decidiq-plt-02`, NL Portal: "backend/zgw/zaken-api/src/main/kotlin/nl/nlportal/zakenapi/service/ZakenApiService.kt:163 documents filtered by vertrouwelijkheid whitelist; [...] ZaakDocumentResource.kt:40 download after ownership check". No URL recorded.
- `sib-decidiq-plt-02`, xxllnc Zaken PIP: "backend/perl-api/lib/Zaaksysteem/Controller/Plugins/PIP/File.pm:192 refuses files not publish_pip; [...] PIP.pm:149 case access check after DigiD/eHerkenning login [reached on /pip/zaak/<id> documents; case-bound documents, not meeting papers]". No URL recorded.

The decidiq matrix row `plt-02` carries the same name and this note: "A council member or citizen can sign in with DigiD or eHerkenning through portaliq's generic OIDC broker, but only when the organisation fronts it with its own OIDC identity provider. Nothing ships a direct DigiD or eHerkenning connection, and decidiq's own contribution still describes the login as deferred."

## What changes

- **A second login route beside the OIDC one.** Per organisation and per provider, the administrator picks `oidc` (today's route, unchanged) or `broker` (integriq). The login screen reads the choice and draws the same button either way.
- **Start through integriq.** Portaliq writes a single-use state row, then sends the browser to integriq's start address with the organisation, the provider, the requested trust level, the consumer id and a relay state.
- **Redeem the code server-to-server.** On return, portaliq consumes the state row, posts the one-time code to integriq's exchange endpoint with its consumer secret, and receives the signed subject envelope once.
- **Check every claim before trusting it.** `use`, `iss`, `audience`, `organisation`, `provider` and `exp` must match what this login started. A mismatch ends the login with the same outcome as every other failure.
- **Mint the existing portal session.** The envelope's subject becomes the `portalAccount` identity reference, its `trust` becomes the session's trust, and `PortalSessionService::issueSession()` mints the bearer exactly as the OIDC callback does.
- **A failed login lands on the login screen, not on raw JSON.** One message for every reason, so the page tells a prober nothing.

## Rows this closes

| Matrix | Row id | Row name | Own rating | What is missing |
|---|---|---|---|---|
| portaliq | `sig-digid` | Sign in with DigiD as a resident. | partial | A DigiD login that does not need the organisation's own OIDC broker. |
| portaliq | `sig-eherkenning` | Sign in with eHerkenning as a business user. | partial | The same, for eHerkenning. |
| portaliq | `cmp-sig-eidas` | Sign in from another EU country with an eIDAS means. | partial | The same, for eIDAS. |
| portaliq | `sib-decidiq-plt-02` | Open confidential papers after signing in with DigiD or eHerkenning. | partial | A government login every organisation can switch on, carrying the trust level a `minTrust` gate reads. |
| decidiq | `plt-02` | Open confidential papers after signing in with DigiD or eHerkenning. | partial | Portaliq's half as above; decidiq's half is named under sibling halves. |

## Existing work it builds on

- `openspec/changes/archive/2026-07-24-portal-oidc-broker-login`: the OIDC route, the per-organisation config in `PortalOrganisationConfigService`, the `portalOidcState` store and the claim mapper presets. This change adds a route beside it and removes nothing.
- `openspec/specs/supplier-portal/spec.md`: the requirements "Per-organisation OIDC broker configuration", "OIDC callback validates the ID token and fails closed on every error" and "Broker LoA maps to portal trust, under-privileging on ambiguity". The broker route keeps the same fail-closed posture.
- `openspec/changes/portal-auth-edge-session-hardening` (open): the `portalSession` row every minted bearer gets, and revocation.
- `openspec/changes/portal-identity-space` (open): REQ-PIS-002, account matching on the identity reference first.
- Integriq `openspec/changes/idp-broker-envelope-runtime` (open) and integriq `openspec/specs/digid-eherkenning-auth-adapter/spec.md`: the envelope claims, the 60 second code, and `POST /api/idp/envelope/exchange` (integriq `appinfo/routes.php:372`).

## Out of scope

- Talking SAML or OIDC to Logius, a broker vendor or an eIDAS node. That stays in integriq.
- Machtigen and eHerkenning chain mandates. The lane change `signin-eherkenning-branch` and the existing mandate service cover what portaliq does with them.
- Moving accounts made through an organisation's own OIDC broker onto integriq pseudonyms. The identity reference differs between the two routes, so an organisation that switches starts new accounts. See design.md, risks.
- Single logout at the broker. The idle warning and single sign-on change `signin-session-idle-warning-and-sso` covers the OIDC route; the integriq route has no logout contract yet.

## Sibling halves

- **ConductionNL/integriq owes** a browser-facing start endpoint that calls `GovernmentIdpAdapterInterface::beginAuthentication()` with `{organisation, consumer, trust, relayState}`, and a callback that sends the browser back to the consumer's return address with the one-time code and the relay state. Neither exists on integriq development: its routes carry only `idpBroker#exchange`. Integriq also owes the vendor SAML Service Provider or OIDC Relying Party behind that interface, which its own proposal names as out of scope and blocked on its Open Decision D1, and a `portaliq` entry in `idp_broker_consumers`.
- **ConductionNL/decidiq owes** a confidential papers collection in its portal contribution with `minTrust` `substantial`. Its `lib/Portal/PortalContributionProvider.php` still says "DigiD/eHerkenning is DEFERRED" at line 18, and its declarations carry `'minTrust' => 'low'` at lines 228, 245, 264, 283, 317 and 340.
