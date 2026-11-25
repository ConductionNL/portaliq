# Tasks: receipt-date-reads-in-the-portals-language

- [x] 1.1 The receipt body names the moment in the portal's language and time zone
  - Files: lib/Service/SubmissionReceiptService.php
  - Test: tests/Unit/Service/SubmissionReceiptServiceTest.php
- [x] 1.2 Live: submit a question and read the receipt in the inbox
  - Done 25 Nov 2026 (live-portaliq-1), same sandbox and resident. Found and fixed live: the receipt read "Wij hebben uw indiening ontvangen op 1 januari 1970 00:00". Nextcloud's `IL10N::l()` reads only a mutable `\DateTime` as a moment and casts anything else to an integer, so the `DateTimeImmutable` became timestamp 1; the unit test's L10N double accepted any `DateTimeInterface` and hid it. The receipt now hands `l()` a `\DateTime` (the identity notice mails had the same mistake and get the same fix), and the doubles in `SubmissionReceiptServiceTest` and `PortalIdentityMailerTest` behave as Nextcloud does. After the fix, with the instance's time zone Europe/Amsterdam, a question sent at 14:31:58 UTC reads "Wij hebben uw indiening ontvangen op 25 november 2026 15:31 (referentie WMEBV-0C41D9F5BE3606AE)" and its `receivedAt` is "2026-11-25T14:31:58+00:00".
