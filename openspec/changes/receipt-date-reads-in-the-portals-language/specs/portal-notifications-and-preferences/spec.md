# portal-notifications-and-preferences Delta: receipt-date-reads-in-the-portals-language

## ADDED Requirements

### Requirement: A receipt names the moment in words of the portal's language

The ontvangstbevestiging written after a portal submission SHALL name the moment of receipt in the portal's language and the instance's time zone, as a date in words and a time to the minute. The machine fields (`receivedAt`, `submittedAt`) SHALL stay ISO 8601.

#### Scenario: A Dutch portal's receipt
@e2e exclude the receipt is written server-side; covered by SubmissionReceiptServiceTest::testTheReceiptNamesTheMomentInTheReadersWords
- **GIVEN** a Dutch portal on an instance in Europe/Amsterdam
- **WHEN** a resident submits at 07:41 UTC on 16 October 2026
- **THEN** the receipt reads "ontvangen op 16 oktober 2026 09:41"
- **AND** its `receivedAt` is "2026-10-16T07:41:00+00:00"
