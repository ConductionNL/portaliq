# Design: submission-row-actions

## D1. Register again reuses the receipt write, for one stored record

`SubmissionReceiptService::record()` writes the receipt and the proof log in one go, right after a create. Register again has the proof log already, so it needs only the first half: write the `portalMessage` receipt for the record's `subjectRef`, `organisation` and `payloadCopy`, then update the same record with `receiptMessageRef` and `deliveryStatus: delivered`. The record is not copied and its `submittedAt` does not move: the evidence says when the resident submitted, and the receipt's own timestamp says when it was written.

A write that fails sets `deliveryStatus: failed` again and answers 502 with a plain message. A `delivered` record answers 409: a second receipt for one submission would tell the resident they submitted twice.

## D2. Who may press it

The endpoint is `#[NoAdminRequired]` with the check in the controller before any read: the caller must be in `portaliq-medewerkers`, the group that reads `portalSubmission` today. The record is read through OpenRegister with the caller's rights, so a record the caller cannot read answers 404, the same answer as one that does not exist.

## D3. Conversation in Talk is configuration over OpenRegister's Talk integration

OpenRegister already links Talk rooms to any object (`POST /api/objects/{register}/{schema}/{id}/talk/new`, the TalkProvider nc-vue's CnTalkTab uses). The action is an `api-call` to that endpoint with the room name `"{actionTitle} {receiptMessageRef}"`, shown while `spreed` is installed. The one gap: an `api-call` toasts and refreshes, it does not open what the call answered. If the dispatcher still lacks it at build time, nc-vue gains `openResponse: "<path>"` on an `api-call` (open the URL or route at that path of the answer), a generic key any app can use. A row with a linked room reads the link first (`GET .../talk`) and opens that room.

## D4. Link a form writes one field

The action is an `open-form` with one select field whose options are the portal's `portalFormBinding` records (`portal` = the submission's portal), saved as an `object-op` patch of `formBinding` only. A write guard, as `NoticeWriteGuardListener` does for notices, protects the rest: a `portalSubmission` update that changes any field other than `formBinding` or `deliveryStatus`/`receiptMessageRef` (the service's own writes) is refused, so the proof fields stay append-only even though the list offers Edit.

## D5. Copy and delete go

Ruben decided on 3 Nov: a submission is a proof of receipt, so the list offers only the board's four entries. The index page's built-in Copy and Delete row actions are switched off (`showCopyAction`, `showDeleteAction`), and so are the selection's Copy selected and Delete selected (`showMassCopy`, `showMassDelete`), or the toolbar would still offer them. A submission leaves the log only through the retention and archival rules, which keep their own record of what was removed and why. The register's delete rights are not changed here; this change is about what the list offers.
