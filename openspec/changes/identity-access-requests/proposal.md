# Proposal: identity-access-requests

## Why

A portal user who needs to see an organisation's cases, for example a
bookkeeper for a client company, has no way to ask. The backend shipped with
`portal-identity-and-the-organisations-cases` (open, 15 of 15 tasks checked,
T12 "Ask for access you do not have; the owner receives the request and answers
it in the product"): `POST /portal/api/identity/access-requests` and
`GET /portal/api/identity/access-requests` are routed (`appinfo/routes.php:281-282`).
Read at `eeda3fa`, three things are missing:

- No screen in `src/portal`, `src/site` or `src/manifest.json` calls either
  route.
- The owner side has no route at all. `PortalAccessRequestService::forOwner()`
  and `decide()` (`lib/Service/Identity/PortalAccessRequestService.php:111,190`)
  have no caller in `lib/`.
- A granted request changes nothing. `decide()` writes `state: granted` and
  records no `portalMandate`, so the asker still sees no case.

Two rows in the portaliq parity matrix (`openspec/parity/capabilities.json`,
compared 2026-09-26) name it. Both are in portaliq's core area (identity),
which is why the OpenSpec pass of 2026-09-27 decided `build`.

**`id-request-access`**, "Ask for access to an organisation's cases you do not
already hold." Portaliq `no`, built.state `built`: "tasks.md T12 'ask for access
you do not have' marked done; the only exerciser is the API-only e2e spec."
No competitor is rated `yes`. Liferay DXP is `partial`:
"https://learn.liferay.com/w/dxp/sites/site-membership/changing-site-membership-type
'Restricted: The site appears in the My Sites application, but users must
request membership to join.' This is access to a site, not to an
organisation's cases."

**`id-my-access-requests`**, "See the access requests you have made and how
they were answered." Portaliq `no`, `built`: "Read side of id-request-access,
equally unreached." No competitor is rated `yes` or `partial`.

## What changes

- On the signed-in portal's "My cases" page, a link "Ask for access to cases"
  opens a short form: whose cases (a KvK number or a name), and why. Below it,
  your own requests with their state.
- A staff screen in the portaliq admin app, "Access requests", lists the
  pending requests of the organisation. A staff member grants or refuses each
  one, with a reason when refusing.
- A grant records an active `portalMandate` for the asker, on behalf of the
  named party, so the cases appear on the asker's "My cases" at the next load.
- Answering is an ADR-023 action, `portal.answer-access-request`, seeded to
  administrators and grantable to a group.

## Rows this closes

| matrix | row | name | own rating | what is missing |
| --- | --- | --- | --- | --- |
| portaliq | `id-request-access` | Ask for access to an organisation's cases you do not already hold | no | the form, the owner's answer, and a grant that grants |
| portaliq | `id-my-access-requests` | See the access requests you have made and how they were answered | no | the list |

## Existing work it builds on

- `portal-identity-and-the-organisations-cases` shipped
  `PortalAccessRequestService` and the two portal routes. This change adds the
  screens, the owner's route and the mandate a grant records. It does not
  change how a request is stored.
- `portalMandate` and `PortalMandateService`
  (`lib/Service/Identity/PortalMandateService.php`) already scope "My cases"
  by the mandates an identity holds; a grant only writes one more.
- `cases-my-cases-page` (this OpenSpec pass) builds the "My cases" page the
  link sits on.

## Out of scope

- Asking a third party (the company itself) to approve. The organisation that
  runs the portal answers.
- A mandate that reaches down a party tree. A grant records `reach:
  organisation`; staff can widen it on the mandate record.
