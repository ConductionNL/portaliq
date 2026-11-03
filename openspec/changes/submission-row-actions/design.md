# Design: submission-row-actions

## D1. Register again reuses the receipt write, for one stored record

`SubmissionReceiptService::record()` writes the receipt and the proof log in one go, right after a create. Register again has the proof log already, so it needs only the first half: write the `portalMessage` receipt for the record's `subjectRef`, `organisation` and `payloadCopy`, then update the same record with `receiptMessageRef` and `deliveryStatus: delivered`. The record is not copied and its `submittedAt` does not move: the evidence says when the resident submitted, and the receipt's own timestamp says when it was written.

A write that fails sets `deliveryStatus: failed` again and answers 502 with a plain message. A `delivered` record answers 409: a second receipt for one submission would tell the resident they submitted twice.

## D2. Who may press it

The endpoint is `#[NoAdminRequired]` with the check in the controller before any read: the caller must be in `portaliq-medewerkers`, the group that reads `portalSubmission` today. The record is read through OpenRegister with the caller's rights, so a record the caller cannot read answers 404, the same answer as one that does not exist.

## D3. Conversation in Talk is configuration over OpenRegister's Talk integration

OpenRegister already links Talk rooms to any object (`POST /api/objects/{register}/{schema}/{id}/talk/new`, the TalkProvider nc-vue's CnTalkTab uses). The action is an `api-call` to that endpoint with the room name `"{actionTitle} {receiptMessageRef}"`, shown while `spreed` is installed. The one gap: an `api-call` toasts and refreshes, it does not open what the call answered. If the dispatcher still lacks it at build time, nc-vue gains `openResponse: "<path>"` on an `api-call` (open the URL or route at that path of the answer), a generic key any app can use. A row with a linked room reads the link first (`GET .../talk`) and opens that room.

## D4. Link a form writes one field

The action is an `open-form` with one select field whose options are the portal's `portalFormBinding` records (`portal` = the submission's portal), saved as an `object-op` patch of `formBinding` only. `NoticeWriteGuardListener`'s pattern guards the rest: a `portalSubmission` update that changes any field other than `formBinding` or `deliveryStatus`/`receiptMessageRef` (the service's own writes) is refused, so the proof fields stay append-only even though the list offers Edit.

## D5. Kopiëren and Verwijderen stay

The board draws four entries; portaliq offers Kopiëren and Verwijderen today. Users keep their features, so both stay, after the board's four. Whether a proof-of-receipt log should offer copy and delete at all is a product question for Ruben (DECISIONS-FOR-RUBEN); until he answers, the board gets the two entries in a board PR, or the list drops them, not both.
