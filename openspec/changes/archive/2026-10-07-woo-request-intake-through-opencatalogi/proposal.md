# Proposal: woo-request-intake-through-opencatalogi

## Why

A Woo request a citizen sent through a portal form never started its statutory term. `PortalIntakeDeliveryJob` delivered every submission with `PortalObjectWriter::createAnonymousObject()`, straight into OpenRegister. opencatalogi's Woo intake never ran, so no `WOO-` reference was minted, no term was armed and no `dueAt` existed. The citizen still got a portal reference, so it read as success.

## What changes

- A form binding gets `deliverTo` (`case` by default, or `wooRequest`).
- A `wooRequest` submission goes to opencatalogi through the contribution provider portaliq already locates: `PortalWooRequestDelivery` calls `receiveWooRequest()` on it (opencatalogi `portal-woo-request-intake`). opencatalogi mints the reference and arms the term, counted from when the citizen sent the request.
- Only an armed term with a due date registers the submission. The submission keeps the minted `externalReference` and the `dueAt`, and the reference page quotes both.
- A term that did not start, a refusal, or a missing opencatalogi marks the submission failed with the reason. The citizen reads that the request is not registered yet and is asked to get in touch. No deadline is quoted.

## Without opencatalogi

portaliq boots and works as before. `IAppManager::isInstalled('opencatalogi')` is checked first, the provider and its method are duck-typed, and a `wooRequest` submission is then marked failed with "opencatalogi is not installed".
