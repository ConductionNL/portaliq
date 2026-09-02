# Design: portal-task-delivery

## D-1 · The proxy, not a contribution collection

Portal tasks are NOT an OpenRegister collection: they live in openregister's
own `oc_openregister_tasks` table behind a subject-scoped REST seam guarded by
an HS256 `X-Portal-Subject` assertion. The contribution registry's collection
vocabulary (register/schema/scopeField) therefore cannot describe them, and
forcing them through it would either fake a schema or bypass the seam's own
authorisation (party match, audit-on-denial, upload constraints).

So "Mijn taken" is a FIXED shell surface, exactly like the unified inbox
(portal-inbox-v2 T05): a synthetic nav entry the SPA appends for authenticated
subjects, backed by three portaliq endpoints under `/portal/api/tasks`. The
aggregated contributions response announces the surface
(`tasks: {enabled: bool}`) so the SPA shows the entry only when the seam can
actually be reached (openregister installed + signing secret configured), and
the anonymous aggregate never carries it.

## D-2 · Assertion stays server-side

The seam contract (openregister#3282): reads and completion are authorised by a
short-lived HS256 assertion, iss `portaliq`, `use: assertion`, verified against
app-config `openregister/portal_assertion_secret` with fallback
`portaliq/jwt_signing_secret`; unconfigured refuses all.

Portaliq already mints exactly this token: `PortalSessionService::issueAssertion()`
→ `PortalJwtService::createAssertion()` (60s TTL, signed with the dedicated
`jwt_signing_secret` sessions require). The task gateway reuses it, the same way
`PortalActionForwarder` does for A6 endpoint actions. The browser holds ONLY its
bearer session token; the assertion is minted per forwarded request inside
`PortalTaskGateway` and appears in no response body, ever. On a co-installed
instance the seam therefore works with zero extra configuration: the fallback
secret is the one portaliq already has.

An openregister-side 401 (`portal-subject-*`) reaching the proxy means
portaliq's OWN assertion was refused: a configuration defect (secret mismatch),
never the resident's fault. The proxy maps it to 503 `task-service-unavailable`
with a plain-language body instead of relaying a misleading "log in again".

## D-3 · Refusal mapping (proxy → resident)

| openregister answers | proxy answers | resident reads (SPA, Dutch, B1) |
|---|---|---|
| 200 | 200, body passed through | the task / the list |
| 401 `portal-subject-*` | 503 `task-service-unavailable` | "De taken zijn nu niet beschikbaar. Probeer het later opnieuw." |
| 404 `no-such-task` | 404 `no-such-task` | "Deze taak bestaat niet of is niet van u." |
| 400 `upload-constraint` | 400 `upload-constraint` | the constraint, named (count/size/type/required) |
| 409 `task-closed` | 409 `task-closed` | "Deze taak is al afgerond." |
| transport failure / non-JSON | 502 `task-service-unreachable` | the same plain unavailable message |

No portaliq bearer → 401 before any forward (PortalAuthMiddleware +
controller re-derivation, the ContributionController pattern). The unmatched
party keeps openregister's unrevealing 404: the proxy adds no oracle.

## D-4 · The worker drains in-process, and why not the REST fallback

Portaliq's normal deployment shape is co-installed with openregister
(ADR-046; `manifest.dependencies` lists openregister). The worker therefore
resolves `OCA\OpenRegister\Service\Portal\PortalTaskDeliveryService` from the
server container BY NAME at run time (the PortalObjectReader pattern:
`class_exists` + container get, no compile-time dependency) and calls
`pending()` / `markDelivered()` / `markFailed()` directly.

The REST fallback for a split deployment exists on the openregister side
(`GET /apps/openregister/api/portal-tasks/deliveries`,
`POST .../deliveries/{uuid}/delivered|failed`, administrator-gated) and is the
documented path for an external consumer. It is NOT built here: two consumers
of one ledger is a recipe for double delivery, and portaliq co-installs. When
openregister is absent the job logs at debug and does nothing.

## D-5 · Worker semantics: idempotent per row, failures isolated

Cadence: a TimedJob every 5 minutes (`setInterval(300)`, time-insensitive),
declared in `appinfo/info.xml`. Batch: `pending(limit: 50)` per run; the ledger
hands rows oldest first, so a backlog drains in order.

Per `portal-inbox` row:
1. Idempotency probe: read the party's own `portalMessage` rows filtered on
   `deliveryUuid` (the ledger row's uuid, stamped on every message this worker
   writes). A hit means an earlier run wrote the message and crashed before
   settling; settle (`markDelivered`) without writing a duplicate.
2. Otherwise write the message through `PortalObjectWriter::createObject()`
   (scope stamped server-side), carrying subject line, B1 body (title, due
   date, the re-ask reason when present), `taskUuid` (the deep link) and
   `deliveryUuid` (the idempotency key). Then `markDelivered`.
3. A null write (writer degrades, never throws) → `markFailed('portal message
   write failed')`; the row stays visible as failed in the caseworker's
   delivery state, which is the honest answer.

Per `mail` row: resolve the party's own `portalAccount` by subjectRef (the
NotificationDispatchJob lookup), validate the address, send a privacy-minimal
bilingual mail (organisation name + portal deep link ONLY, never task or case
content, the same posture as the existing notification mail) via `IMailer`.
No account, no address, invalid address, or a send failure → `markFailed` with
the reason. Mail is at-least-once: a crash between send and settle re-sends one
mail on the next run; a duplicate nudge mail is harmless, a silently dropped
one is not (design choice mirrored from the ombudsman finding the notification
job documents).

Every row is wrapped in its own try/catch: a Throwable on row N is logged,
`markFailed` is attempted, and row N+1 still runs. The job never lets an
exception reach the cron runner.

The party reference in the ledger is `party:<subjectRef>` (frozen from the
case's initiator by the engine). The worker strips the `party:` prefix to
address the inbox scope and the account lookup; a reference without the prefix
is refused (`markFailed`) rather than guessed at.

## D-6 · Deep link without a router

The public portal SPA navigates by state, not URLs. The inbox row for a task
message carries `taskUuid`; `InboxPage` renders "Bekijk taak" for such rows and
calls up into the shell, which switches the active nav entry to "Mijn taken"
with that uuid preselected. `TasksPage` opens the detail (or the plain
not-found message when the task has since closed and vanished from the open
list: the completion states are honest, not cached).

Two `portalMessage` schema properties are added for this (`taskUuid`,
`deliveryUuid`, both optional strings; schema version bumped): OpenRegister
drops undeclared properties on save, so declaring them is load-bearing, not
documentation.

## D-7 · What the seam did not provide (recorded, not worked around)

- The ledger row carries no `organisation`/tenant: the message payload has
  `partyReference` only. The worker scopes by subjectRef alone (globally
  unique, per the portalAccount lookup convention) and stamps the account's own
  organisation on the inbox message when the account has one.
- `PortalTaskDelivery::jsonSerialize()` is consumed in-process; the worker
  reads getters, so a wire-shape drift in the REST fallback would not touch it.
