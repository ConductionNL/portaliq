# Proposal: an action forward survives a slow answer

## Why

Live round 3 on the Zuiddrecht demo (14 Oct): a Woo request started from a dossier answered 502 `forward_failed` after 10.7 s while dossiq had already written the case. The resident read "Opslaan is niet gelukt", sent again, and got a second and a third case (2026-0115, 0116, 0118). The forward gave up after ten seconds; dossiq's dossier-linked intake takes longer than that, the tile route about six.

## What changes

- The forward waits 30 seconds, like the task gateway.
- A form makes one submission key when it opens and sends it with every send of the same answers as the `Idempotency-Key` header. Portaliq relays a key that is a plain token (16 to 128 of `A-Z a-z 0-9 _ -`) to the domain app and drops anything else. The domain app may answer a repeated key with what the first send made; dossiq does (dossiq `portal-submission-is-made-once`).
- A forward that times out answers 504 `forward_timeout`, not 502. The form then says the request may still be processing and links to Mijn zaken, instead of "Opslaan is niet gelukt". A domain app that answers `submission_processing` for a key it is still working on gets the same sentence.
- After a successful send the form starts a new submission with a new key.

## Consumers

dossiq `startWooVerzoek` and `startWooVerzoekAlgemeen` (the generic portal action endpoint). Any other endpoint action may adopt the header; one that ignores it behaves as before.
