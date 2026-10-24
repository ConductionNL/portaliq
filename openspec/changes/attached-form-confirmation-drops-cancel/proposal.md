# Proposal: attached-form-confirmation-drops-cancel

## Why

Seen in the Zuiddrecht go-live rehearsal R2: after a resident sent a Woo request from a dossier, the confirmation ("Wij hebben uw Woo-verzoek ontvangen") still had "Annuleren" under it. The request was already sent, so there was nothing to cancel, and the button read as a way to take it back.

## What changes

- An attached action that runs in steps shows "Annuleren" until it is sent. Once its confirmation shows, the same place offers "Sluiten", which puts the form away and shows the record's actions again.

## Not changed

- An attached action without steps closes on a successful send, as before.
- The confirmation's own text, from the action's `confirmation`.
