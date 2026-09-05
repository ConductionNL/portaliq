# Contract: contribution-landing-page-action

## Consumers

pipelinq (phase 0, this change ships the Portaliq side only; pipelinq's own
consumer half is phase 4's `marketing-landing-pages-via-portaliq`). Any other
fleet app may consume the same contract later — nothing here is pipelinq-
specific.

## Transport

**Not HTTP.** Same-instance ADR-041 typed event dispatch
(`OCP\EventDispatcher\IEventDispatcher::dispatchTyped()`), synchronous. There
is no REST endpoint for either direction — see design.md "Decisions" for why
the portal-contribution endpoint-action-forward pattern and a new HTTP route
were both rejected.

## Direction 1: create a landing page (contributing app → Portaliq)

### Request — `OCA\Portaliq\Event\LandingPageRequestedEvent`

Constructed positionally (a published cross-app contract, per ADR-041 —
`class_exists()`-guard the FQCN, fail closed/throw if Portaliq is not
installed):

```php
new \OCA\Portaliq\Event\LandingPageRequestedEvent(
    sourceApp: 'pipelinq',
    portal: 'open-tilburg',                 // the target portal's `slug`
    route: '/campagne/YOUR_ROUTE_HERE',      // must be unique within the portal
    title: 'Webinar: AI voor gemeenten',
    locale: 'nl',
    article: [
        'summary' => 'Praktische AI-toepassingen voor de publieke sector.',
        'body' => "## Programma\n\n- ...",   // markdown
        'heroImageRef' => null,               // string ref/URL, or null
        'links' => [],                        // [{label, url}], optional
    ],
    form: [
        'fields' => [
            ['id' => 'name', 'label' => 'Naam', 'type' => 'text', 'required' => true],
            ['id' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => true],
        ],
        'submitLabel' => 'Aanmelden',
        'consentText' => 'Ik ga akkoord met de verwerking van mijn gegevens.',
    ],
    utm: [
        'campaign' => 'webinar-ai-2026q4',
        'source' => 'newsletter',
        'medium' => 'email',
    ],
    externalReference: 'pipelinq:campaign:00000000-0000-0000-0000-000000000000',
    correlationId: 'YOUR_CORRELATION_ID_HERE',
);
```

`callback` from the task brief's shape is **not a field on this event** — see
design.md: delivery back to the caller is the SECOND typed event (Direction
2), addressed by `sourceApp` recorded on the created `form`, not by a URL.

### Response — the same event instance, read immediately after `dispatchTyped()`

```php
$dispatcher->dispatchTyped($event);
if ($event->isHandled() === false) {
    // Portaliq is installed but its listener did not run — treat as a
    // platform fault, not a validation rejection.
}
if ($event->getError() !== null) {
    // one of: unknown_portal | duplicate_route | invalid_article | invalid_form
    // no page or form was created.
}
$pageId = $event->getPageId();       // string, OpenRegister object id
$route = $event->getRoute();          // string, echoes the request route (never adjusted)
$publicUrl = $event->getPublicUrl();  // string|null — null when the portal has no domain configured
$formId = $event->getFormId();        // string, OpenRegister object id of the created `form`
```

## Direction 2: a visitor submits the form (Portaliq → contributing app)

### Request — `OCA\Portaliq\Event\LandingPageFormSubmittedEvent`

Dispatched by Portaliq after the visitor's submission is durably written to
`landingPageSubmission`. The consuming app defines ITS OWN copy of this event
class in `OCA\{App}\Event\LandingPageFormSubmittedEvent` (ADR-041: no shared
library) with a matching constructor signature; Portaliq's dispatch listener
resolves that FQCN by the `sourceApp` recorded on the `form` object and
`class_exists()`-guards it — **failing SAFE (logged, not thrown)** when the
class does not exist yet, so an uninstalled/not-yet-built consumer never
turns a visitor's successful submission into an error (see design.md Risk 1).

```php
// OCA\Pipelinq\Event\LandingPageFormSubmittedEvent (phase 4, pipelinq's own file)
new \OCA\Pipelinq\Event\LandingPageFormSubmittedEvent(
    sourceApp: 'pipelinq',
    formId: 'FORM_OBJECT_ID',
    pageId: 'PAGE_OBJECT_ID',
    pageRoute: '/campagne/webinar-ai-2026q4',
    portal: 'open-tilburg',
    externalReference: 'pipelinq:campaign:00000000-0000-0000-0000-000000000000',
    values: ['name' => 'J. de Vries', 'email' => 'JANE.DOE@EXAMPLE.COM'],
    utmFirstTouch: ['campaign' => 'webinar-ai-2026q4', 'source' => 'newsletter', 'medium' => 'email', 'term' => null, 'content' => null],
    utmLastTouch: ['campaign' => 'webinar-ai-2026q4', 'source' => 'linkedin', 'medium' => 'social', 'term' => null, 'content' => null],
    referrer: 'https://www.linkedin.com/',
    submittedAt: '2026-09-04T12:00:00+00:00',   // server-stamped, ISO-8601
    nonce: 'SERVER_GENERATED_NONCE_HERE',        // server-stamped, replay-resistance only
    correlationId: 'YOUR_CORRELATION_ID_HERE',
);
```

### Response (optional ack)

```php
$this->eventDispatcher->dispatchTyped($event);
$event->setLeadId('LEAD_OBJECT_ID');  // consumer writes this back; Portaliq does not read it
$event->setHandled(true);
```

Portaliq does not depend on the result slot being written — the submission is
already durable before this event dispatches. The result slot exists purely
so a future admin view could show delivery status; unused by this change.

## Error Codes (Direction 1 only — Direction 2 has no error channel, it is fire-and-forget-with-optional-ack)

| Code | Condition |
| --- | --- |
| `unknown_portal` | No `portal` object with the given `slug` exists, or its status is not `published` |
| `duplicate_route` | Another `page` in the same portal already has this `route` (case-insensitive) |
| `invalid_article` | `article.summary` or `article.body` missing/empty |
| `invalid_form` | `form.fields` empty, or any field missing `id`/`label`/`type`, or `submitLabel` empty |
| `write_failed` | Validation passed but the OpenRegister write itself failed (platform fault, not a request error) — no page or form was created |

## Versioning

Unversioned — this is a same-instance, same-deploy contract (both apps run
from the same Nextcloud codebase revision policy the fleet already assumes
for ADR-041 events). A breaking change to either event's constructor
signature requires a coordinated PR across every consumer, exactly like
`DecisionRequestedEvent`'s own frozen-signature convention.

## Breaking Change Policy

Additive-only by default (new optional trailing constructor parameters with
defaults). A genuinely breaking change follows the same coordinated,
versioned-change discipline `decidesk-decision-events` already documents.

## SLA

Synchronous, in-process — no network hop, no queue. Expected to complete
within the same request/response cycle as the caller's own action (page
creation) or Portaliq's own submission write (form handoff); no additional
timeout budget is introduced.
