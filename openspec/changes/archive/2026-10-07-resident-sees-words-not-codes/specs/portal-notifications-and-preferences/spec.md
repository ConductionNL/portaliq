## ADDED Requirements

### Requirement: A receipt, a notification mail and a task notice are written in the portal's language only

The submission receipt (`SubmissionReceiptService`), the notification e-mail and push (`NotificationDispatchJob`) and the delivered task notice and mail (`PortalTaskDeliveryJob`) MUST each be written once, in the language of the resident's portal, chosen by `PortalNoticeLanguage` the same way change notices are (REQ-NAP-010): the portal's first locale, else Dutch. They MUST NOT join two languages with " / " or a blank line. A task notice MUST find the portal through the resident's own portal account.

#### Scenario: The receipt of a portal in English
- GIVEN the organisation's portal lists `en` as its first locale
- WHEN a resident sends a form
- THEN the receipt's subject reads "Confirmation of receipt, reference WMEBV-..." and carries no Dutch line
- @e2e exclude pinned by `SubmissionReceiptServiceTest::testTheReceiptIsInThePortalsLanguageOnly`

#### Scenario: The notification mail of a portal with no locale
- GIVEN the organisation's portal names no locale
- WHEN a notification mail is sent
- THEN subject and body are Dutch only
- @e2e exclude pinned by `NotificationDispatchJobTest::testSendsAContentFreeEmailAndLogsASentAttempt` and `::testTheEmailIsInThePortalsLanguageOnly`

#### Scenario: A task notice in the resident's portal language
- GIVEN the resident's portal account belongs to an organisation whose portal lists `en` first
- WHEN a task is delivered to their portal inbox
- THEN the notice is English only
- @e2e exclude pinned by `PortalTaskDeliveryJobTest::testAnInboxNoticeIsInThePortalsLanguageOnly` and `::testAskMailIsInThePortalsLanguageOnly`
