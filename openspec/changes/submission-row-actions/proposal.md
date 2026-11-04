---
kind: code
---

# Proposal: submission-row-actions

## Why

Board PtInzendingen opens a submission's row menu on a failed receipt ("Grof afval laten ophalen", Mislukt) and draws four entries: Bewerken, Formulier koppelen, Gesprek in Talk and Opnieuw registreren. portaliq draws the index page's built-in Bewerken, Kopiëren and Verwijderen. Three of the board's entries are features that do not exist:

- **Opnieuw registreren.** supplier-portal already promises that a failed receipt stays retriable ("A receipt or log failure never loses the submission"), but nothing retries it. An employee who sees Mislukt can only read the row.
- **Gesprek in Talk.** An employee who has to discuss a submission with colleagues starts a Talk room by hand and pastes the reference in.
- **Formulier koppelen.** A submission names the action it came from, not the request form it came through. A row written by an older form, or by an action without a form, cannot be traced to one.

The board is canon and users keep their features, so the call is to build the three, not to amend the board. The board also drops Kopiëren and Verwijderen, and that drop stands: copying or deleting evidence of receipt is not something a list should offer.

## What changes

- **Register again.** A row whose `deliveryStatus` is `failed` or `pending` offers "Register again". It writes the ontvangstbevestiging from the record's own `payloadCopy`, links it through `receiptMessageRef` and sets `deliveryStatus` to `delivered`. A failing retry leaves the row `failed`. A `delivered` row offers no retry and the endpoint refuses one.
- **Conversation in Talk.** Every row offers "Conversation in Talk" while Talk is installed. It starts a Talk room linked to the submission through OpenRegister's Talk integration, named after the submitted action and its reference, and opens it. A row that already has a linked room opens that room instead of starting a second one.
- **Link a form.** Every row offers "Link a form". A dialog lists the request forms (`portalFormBinding`) of the submission's portal; choosing one stores it on the submission as `formBinding`. The link is the only field the action writes: the proof fields stay as they were.
- **The menu reads as the board.** Bewerken, Formulier koppelen, Gesprek in Talk, Opnieuw registreren, in that order, and nothing else.
- **No copy and no delete.** A submission is a proof of receipt (Ruben, 3 Nov). The list offers neither Kopiëren nor Verwijderen, as a row action or as a selection action. A submission leaves the log only through the retention and archival rules.

## Out of scope

- The submission detail page (`PortalSubmissionDetail`). Its header actions can take the same three actions in a later change.
- Sending the receipt mail again. Register again writes the inbox receipt; the out-of-band mail keeps its own retry (portal-mail-log).
- Resident-side changes. The resident sees the receipt appear in their inbox, nothing else.

## Impact

- `lib/Settings/portaliq_register.json`: `portalSubmission` gains the optional `formBinding` (uuid). Schema 0.3.0 to 0.4.0, register version bump.
- `lib/Service/SubmissionReceiptService.php`: a `registerAgain()` that reuses the existing receipt write for one stored record.
- `lib/Controller/SubmissionAdminController.php` (new) and `appinfo/routes.php`: `POST /api/submissions/{id}/register-again`, staff only.
- `src/manifest.json`: `PortalSubmissions.config.actions` with the three entries and their visibility; `showCopyAction`, `showDeleteAction`, `showMassCopy` and `showMassDelete` off.
- nc-vue: an `api-call` that opens what the call answers (`openResponse`), for the Talk room. Needed only if the dispatcher cannot already do it; see design D3.
- l10n: "Register again", "Conversation in Talk", "Link a form", "Choose the request form this submission came through", "The receipt is registered again", "The receipt could not be registered again".

## Rollback

Revert the commits. Records written meanwhile keep `formBinding` and their new receipt; both are additive.
