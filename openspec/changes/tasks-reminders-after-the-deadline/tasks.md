# Tasks: tasks-reminders-after-the-deadline

## The inbox message

- [ ] **T01**: Add `'overdue' => 'Your task is overdue: %1$s'` to `SUBJECT_KEYS` in `lib/BackgroundJob/PortalTaskDeliveryJob.php` (REQ-TRD-001). Verification: `PortalTaskDeliveryJobTest::testOverdueInboxSubject`.
- [ ] **T02**: `bodyText()` takes the kind and writes "This task was due on %1$s." for `overdue`, plus the optional consequence line (REQ-TRD-001). Verification: `PortalTaskDeliveryJobTest::testOverdueBodyStatesThePastDueDate`.

## The mail

- [ ] **T03**: `deliverMail()` takes the row's kind and picks subject and body keys per kind; `ask` and `re-ask` stay byte-identical (REQ-TRD-002). Verification: `PortalTaskDeliveryJobTest::testReminderMailSubject`, `::testOverdueMailSubject`, `::testAskMailIsUnchanged`.

## Honest failure

- [ ] **T04**: `settleRow()` marks a row with an unknown kind as failed with "unknown delivery kind: {kind}" before any write (REQ-TRD-003). Verification: `PortalTaskDeliveryJobTest::testUnknownKindIsMarkedFailed`.
- [ ] **T05**: A test that the job writes nothing for a task with no ledger row, whatever its due date (REQ-TRD-004). Verification: `PortalTaskDeliveryJobTest::testNoRowNoNotice`.

## End to end

- [ ] **T06**: `tests/e2e/tasks-reminders-after-the-deadline.spec.ts`: with an `overdue` row seeded in the openregister ledger, run the job through `occ background-job:execute`, open the inbox as the resident, see the overdue message, follow it to "Mijn taken" and see "Overdue" (REQ-TRD-001). Blocked on the openregister sibling half for a real rung; a seeded row is enough for this spec.

## Docs, strings and validation

- [ ] **T07**: Dutch strings in `l10n/nl.json` for the new subject, body and mail keys; a docs note for administrators that overdue notices follow the case type's business timer. Verification: `test:l10n`.
- [ ] **T08**: `openspec validate tasks-reminders-after-the-deadline --strict`.
