# Design: operate-portals-per-organisation

Read at portaliq `development` `eeda3fa`.

## What keeps tenants apart today

- `lib/Service/PortalObjectReader.php:233-238` reads OpenRegister with RBAC
  and multitenancy off, because portal subjects are not Nextcloud users. The
  reader's own checks are the tenant boundary.
- `PortalObjectReader.php:220-224` says `organisation` is not an OpenRegister
  filter, and that "broader tenant scoping for anonymous portal reads is a
  follow-up".
- `verifyScope()` (line 1065) drops a row whose scope field is not the
  subject, then calls `organisationMatches()` (line 1048), which returns true
  when the subject's organisation is empty OR the row's organisation is
  empty. A row with no organisation passes for every tenant.
- `lib/Service/CmsReader.php:142,170,217,259,325` filters menus, pages and
  glossary terms by `portal`.
- `lib/Service/PortalResolver.php:118-138` resolves a portal by host with no
  fallback, or by an explicit slug that must exist.
- The register has 48 schemas. 25 carry `organisation` or `portal`; 4 more
  carry only `subjectRef`; 19 carry none of the three (`portalOidcState`,
  `portalPage`, `portalCaseType`, `changeProposal`, `portalReporterContact`,
  `portalReportMessage`, `portalRevealRequest`, `newsItem`, `newsletter`,
  `guardianAudienceFixture`, `schoolEvent`, `eventRsvp`, `eventSignup`,
  `messageThread`, `guardianMessage`, `groupStaffFixture`, `activityOffer`,
  `activitySignup`, `activityAttendance`).
- There is no `occ` command or service that creates a portal. `appinfo/info.xml`
  registers four traffic commands only.

## D1. One declaration per schema, enforced by a census

`lib/Service/Tenancy/SchemaTenancy.php` holds a constant map from schema slug
to one of: `organisation`, `portal`, `subject`, `parent` (with the parent
schema and the reference field, for example `portalReportMessage` through
`reportRef` to `portalReport.portal`), or `global` with a one-line reason.

`tests/Unit/Tenancy/SchemaTenancyCensusTest.php` reads
`lib/Settings/portaliq_register.json` and fails for any schema missing from
the map, and for any schema declared `organisation` or `portal` whose
properties lack that field. A new schema cannot ship without a decision.

The per-schema decisions are task T02, one PR, reviewed as a list. The rule:
data that belongs to an organisation's residents or content gets
`organisation` or `portal`, stamped server-side on write by
`PortalObjectWriter`; `global` needs a reason a reviewer can check, such as
`portalOidcState` keyed by a single-use nonce.

## D2. A missing tenant value refuses

`organisationMatches()` takes the schema's declared scope. For a schema
declared `organisation`, a row without an organisation and a subject without
one are both dropped. `portal` works the same against the resolved portal.
`subject` keeps today's behaviour. Nothing changes for a schema that already
carries its value on every row; the change bites only where a value is
missing, which is exactly where a leak would come from.

## D3. The proof walks every route, on two tenants

`tests/e2e/operate-portals-per-organisation.spec.ts`, with a fixture that
provisions organisations `org-a` and `org-b`, one portal each on its own host
(or by `?portal=` where the rig cannot vary the host), and the same records
on both sides: account, messages, cases, a mandate, a submission, a report,
pages, a news item, an event. As a subject of `org-a` it calls every route in
`appinfo/routes.php` under `/portal/api/` and the public site content routes,
and asserts that no response carries an id seeded for `org-b`. A route
added later without a line in the spec's route table fails the spec.

## D4. Provisioning a portal for an organisation

`lib/Service/Tenancy/PortalProvisioningService.php::provision(organisation,
slug, title, host)`:

1. Refuses when `slug` is taken, or when `host` is already in any portal's
   `domains`, whichever organisation holds it.
2. Uses openregister's organisation when its provisioning API exists
   (`saas-multi-tenant`), otherwise requires an existing organisation id.
3. Writes the `portal` object with `organisation`, `slug`, `title`,
   `status: draft`, `domains: [host]` unverified, and `authentication:
   public`.
4. Seeds one menu and one home page through the page provisioning path.
5. Returns the DNS record the administrator must publish, from
   `portal-scoping-and-auth`'s verification step.

Two doors: `occ portaliq:portal:provision` for a hosting party's script, and
a "New portal" action on the Portals admin page (`src/manifest.json`,
`Portals`) that opens a dialog with the same four fields. The portal goes
live only when its host verifies and an administrator sets `status:
published`.

## Risks

- **Tightening breaks a tenant with rows that lack an organisation.** T03
  counts such rows on a live instance before D2 lands, and a repair step
  stamps them from their portal or refuses to guess.
- **A route slips past the proof.** D3's route table fails the spec when a
  route is not listed.
- **Openregister picks physical separation.** Then this proof still holds
  at the portal layer and the census remains the map of what is whose.

## What this deliberately does not do

- No billing, plans or sign-up page.
- No change to how a session is scoped; that is `portal-scoping-and-auth`.
- No per-tenant quotas.
