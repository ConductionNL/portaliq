# Design: case-actions-sign-a-document

Read at portaliq `development` `eeda3fa`, and filinq `development` for the
receiving side.

## Where the signing act is lost today

Filinq's `lib/Portal/PortalContributionProvider.php` declares, for its
`signer` audience, a `signerSigningRequests` collection (via-scoped on
`signerRecord.email` by `scopeClaim: signerEmail`) whose `rowActions` are two
inline endpoint objects, `sign` and `decline`, and three top-level endpoint
actions `sign`, `decline` and `viewDocument`, all `minTrust: substantial`.
Its receiver `lib/Controller/PortalSigningReceiverController.php` reads
`signingRequestId`, `consent`, `signature` and `reason` from the body and the
signer from `$claims['signerEmail']` in the verified assertion (line 320).

Portaliq loses that at four points:

1. `lib/Contribution/CollectionConfigNormaliser.php:109-160`:
   `resolveRowActions()` keeps a `rowActions` entry only when it is a string
   equal to the id of a `type: update` action (`updateActionIds()`, line 125).
   Filinq's inline endpoint objects are dropped.
2. `src/portal/components/PageView.jsx:326-328` maps `rowActions` ids to
   actions and line 380 passes only `type === 'update'` to `CollectionTable`.
   `src/portal/App.jsx:255-264` `onRowAction` can only PATCH with `{}`.
3. `src/portal/App.jsx:268-284` `onAction` posts `'{}'` to
   `/portal/api/actions/{app}/{id}` and discards the response ("result UI is
   a follow-up"). No row id can reach the forward.
4. `lib/Service/PortalJwtService.php:171-199` `createAssertion()` mints exactly
   `sub, audience, organisation, trust, jti, use, iat, exp, iss`. There is no
   `signerEmail`, so filinq's receiver refuses every act with 403 by design.

And one point that is filinq's, not portaliq's: a DigiD session has audience
`client` (`lib/Service/OidcClaimMapperService.php:74-80`), and filinq serves
only `data-subject` and `signer`.

## D1. An endpoint action can be a row action

`resolveRowActions()` keeps an entry when it resolves, by id, to either a
`type: update` action or an endpoint action (a non-empty `endpoint`, no
`type`) in the same contribution. An entry may be a string id or an object
with an `id`; an object is reduced to its id. The endpoint, method and
`minTrust` always come from the top-level action that survived
`normaliseActions()`, never from the inline object. So filinq's current
manifest resolves as it stands, and an inline object can never smuggle an
endpoint past the SSRF guard in `isForwardableAction()`
(`lib/Controller/ContributionController.php:1593`).

The normalised row action carries a `kind` of `update` or `endpoint`, so the
SPA does not guess.

## D2. A row-scoped forward proves the row first

New route `POST /portal/api/collections/{register}/{schema}/{id}/actions/{actionId}`,
`contribution#rowAction`, registered beside `contribution#object`
(`appinfo/routes.php:296`) and before the `/portal/{path}` catch-all.

`ContributionController::rowAction()`:

1. Resolves the subject; 401 without one.
2. Finds the collection by `?collection=` exactly as `object()` does
   (`authorisedCollection()`, line 591), and 403 unless that collection's
   normalised `rowActions` names `actionId` as an endpoint row action.
3. Re-checks the collection's and the action's `minTrust`.
4. Reads the row through `PortalObjectReader::readObject()` with the
   collection's scope, `scopeClaim` and `via`, the same call `object()` makes
   at lines 604-616. Null gives the single 404, and nothing is forwarded.
5. Rebuilds the body from the action's `fields` whitelist, then stamps the
   row id under the action's declared `rowField` (filinq: `signingRequestId`).
   The stamp overwrites any client value. An endpoint row action with no
   `rowField` is dropped at normalisation.
6. Forwards through `PortalActionForwarder::forward()` and relays status and
   body, as `action()` does at lines 1437-1505, including the audit record
   with verb `forward`.

The browser sends the row id only in the path. The target reaching filinq is
the row portaliq read under the resident's own scope.

## D3. The resolved scope claim rides inside the assertion

When the forwarded action (row-scoped or not) declares a `scopeClaim`,
`PortalSessionService::issueAssertion()` (line 767) passes the value
`PortalObjectReader::resolveScopeValue()` returns, and `createAssertion()`
adds it as one extra claim named after the declared claim (for
`signerEmail`, the claim `signerEmail`). A claim name that equals a reserved
claim (`sub`, `audience`, `organisation`, `trust`, `jti`, `use`, `iat`, `exp`,
`iss`) is refused at normalisation, so it can never overwrite one. An action
with no `scopeClaim` mints exactly today's nine claims.

The alternative is `subjectField` from `portal-take-assessment`, which stamps
the same value into the body. It is kept, but it does not satisfy filinq: the
body is not covered by the assertion's signature, so a leaked assertion could
be replayed with another body inside its 60 seconds. A claim inside the
signed assertion cannot. This is the option filinq's
`portal-signing-actions` design lists as "(a, preferred)".

## D4. The signing screen

A new `src/portal/components/SigningDialog.jsx`, opened from a row's endpoint
row action when the action id is `sign`, and a `DeclineDialog.jsx` for
`decline`. Both live in their own files. Other endpoint row actions get a
plain confirmation with the action label.

- On open, the dialog calls the row-scoped forward for `viewDocument` when
  the contribution declares it, and renders the returned `contentBase64` as a
  PDF in an `<object>` with a download link beside it. Without
  `viewDocument`, the dialog says the document cannot be shown and offers no
  sign button.
- A checkbox the resident ticks: "I have read this document and I sign it."
  The sign button stays disabled until it is ticked.
- On confirm, it posts `{consent: true}` to the row-scoped forward for
  `sign`. A 2xx closes the dialog, reloads the collection and shows "You
  signed {documentName}." A non-2xx shows the refusal and keeps the dialog
  open.
- Decline asks "Why do you decline?" with a free-text reason, posts
  `{reason}`, and shows "You declined to sign {documentName}."

English source strings with Dutch in `src/portal/i18n/nl.json`.

## D5. Nothing about the signature is portaliq's

Portaliq renders, proves the row and forwards. The status transition, the
evidence, the assurance level and the audit of the signature are filinq's.
Portaliq records only its own forward audit entry.

## Risks

- **A self-declared email as the signer's identity.** If filinq scoped the
  `client` audience by the resident's own contact email, anyone who typed
  another person's address could sign for them. The sibling half says filinq
  scopes by a claim the organisation sets. Portaliq cannot enforce that; it
  forwards what the action declares.
- **Changing a frozen format.** A receiver that rejects unknown claims breaks
  on the extra claim. Only actions that declare a `scopeClaim` get it. Task
  T01 lists every receiver that verifies the assertion and records how each
  treats an unknown claim, before any code changes.
- **Large documents.** `viewDocument` returns base64 in JSON, which filinq's
  own design lists as an open question for large PDFs. The dialog caps what it
  renders inline and falls back to the download link.

## What this deliberately does not do

- It does not add a `signer` audience to portaliq sessions.
- It does not draw a handwritten signature. Filinq accepts an optional
  `signature` payload; a drawing pad is a later change if a case type asks.
- It does not touch `onAction` for page-level endpoint buttons beyond
  showing their result.
