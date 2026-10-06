---
kind: code
depends_on: [woo-request-intake-through-opencatalogi]
---

# Proposal: woo-intake-delivers-to-dossiq

Woo capability programme, round 1, wave 3. Supporting change: it closes no row by itself. It keeps
rows 7.1 and 7.2 yes while the Woo request moves from opencatalogi to dossiq.

| row | text | our rating today | what this change must keep |
| --- | --- | --- | --- |
| 7.1 | A citizen submits a request through a form | yes | a portal Woo form still becomes a request with a running term |
| 7.2 | The request gets a reference the citizen can quote back | yes | the reference page quotes dossiq's case number and due date |

Implements Ruben's decisions **D1** (dossiq owns the Woo request, its intake and its term) and **D12**
(Woo requests require dossiq, with no fallback). This is step 3 of the plan's section "The Woo request
moves to dossiq". It runs beside `opencatalogi/woo-request-intake-hands-over-to-dossiq` (step 2), after
`dossiq/woo-request-takes-over-from-opencatalogi` (step 1).

## Summary

Deliver the portal's Woo request form to dossiq, so the request, its reference and its term live in dossiq while rows 7.1 and 7.2 stay yes.

- Rows: supporting change, closes no row by itself; keeps 7.1 "A citizen submits a request through a form" and 7.2 "The request gets a reference the citizen can quote back" at yes (neither statutory).
- Wave: 3.
- Depends on: `dossiq/woo-request-takes-over-from-opencatalogi` (https://github.com/ConductionNL/dossiq/issues/3289), merged first. Runs beside `opencatalogi/woo-request-intake-hands-over-to-dossiq` (https://github.com/ConductionNL/opencatalogi/issues/1781).
- Decision: D1 (dossiq owns the Woo request) and D12 (Woo requests require dossiq, no fallback), both 2026-10-05.

Build rules: openspec/woo-build-rules.md

## Why

What portaliq does today, read on `development` at ca591037:

- A form binding with `deliverTo: wooRequest` is delivered by `PortalIntakeDeliveryJob` through
  `OCA\Portaliq\Service\Intake\PortalWooRequestDelivery::deliver(array $answers, string $submittedAt): array`.
  It checks `IAppManager::isInstalled('opencatalogi')`, locates opencatalogi's
  `OCA\OpenCatalogi\Portal\PortalContributionProvider` through `PortalProviderLocator::locate('opencatalogi')`,
  and calls `receiveWooRequest($answers, $submittedAt)`. It trusts a due date only on `armed`
  (spec change `woo-request-intake-through-opencatalogi`, 4 of 4).
- dossiq's own Woo actions, `startWooVerzoek` and `startWooVerzoekAlgemeen`, are declared in dossiq's
  `CitizenManifest` and post to `/index.php/apps/dossiq/api/portal/woo-verzoek`. The portal renders
  them as endpoint actions already.

After D1 a Woo request belongs in dossiq. A portal form that still hands it to opencatalogi creates a
request in the app that is about to stop taking them.

## What changes

1. `PortalWooRequestDelivery` delivers to **dossiq**. It checks `isInstalled('dossiq')`, locates
   `OCA\Dossiq\Portal\PortalContributionProvider` through `PortalProviderLocator::locate('dossiq')`,
   and calls `receiveWooRequest(array $answers, string $receivedAt = ''): array`, which
   `dossiq/woo-request-takes-over-from-opencatalogi` (REQ-WTO-001) adds with the exact signature and
   return keys opencatalogi's has: `{outcome, requestId, reference, dueAt, message}`, `outcome` one of
   `armed`, `not-armed`, `refused`, `unavailable`. Its reading of the answer stays as it is.
2. **No fallback** (D12). Without dossiq, or with a dossiq whose provider lacks the method, the outcome
   is `unavailable` with the sentence that Woo requests are handled by dossiq, which is not installed,
   and the submission is marked failed. portaliq never delivers a Woo request to opencatalogi again.
3. The `deliverTo` description in the register and the admin's form binding screen say the request
   goes to dossiq. When dossiq is missing, the binding screen says so, so an administrator learns it
   before a resident does.
4. The Woo request entry the portal offers by default (the home tile and the Woo page) is dossiq's
   `startWooVerzoekAlgemeen` action, which dossiq already declares, not a form binding.

## What does not change

- The reading of the answer: a due date is trusted only on `armed`, and a request whose term did not
  start is marked failed with its reference (REQ of `woo-request-intake-through-opencatalogi`).
- The form fields. They keep opencatalogi's names (`requestedInformation`, `requesterName`,
  `requesterEmail`, `requesterPhone`, `requesterAddress`), because dossiq's `receiveWooRequest()`
  reads exactly those.

## Dependencies

- Planned, dossiq, wave 2: `woo-request-takes-over-from-opencatalogi` (REQ-WTO-001, REQ-WTO-002). This
  change does not start before it is merged.
- Beside it, opencatalogi, wave 3: `woo-request-intake-hands-over-to-dossiq`. While its forward is live,
  a portal on an older portaliq release that still delivers to opencatalogi has its request forwarded to
  dossiq and armed there.

**App absent.** Without dossiq there is no Woo request intake (D12). Without opencatalogi nothing in this
path changes.

## Wave and done

Wave 3. Done means merged on `development` with CI green. 7.1 and 7.2 stay yes; through dossiq they
read `production` only once both a portaliq and a dossiq store release carry the move.
