# Tasks: a-create-stamps-the-moment-it-was-made

- [x] 1.1 The create body stamps the field an action names in `createdAtField`
  - Files: lib/Contribution/CreateBody.php
  - Test: tests/Unit/Contribution/CreateBodyTest.php
- [x] 1.2 Live: ask a question on /contact/vraag and read "Gesteld"
  - Done 25 Nov 2026 (live-portaliq-1), same sandbox and resident: the form posts only `{title, description}` to `/portal/api/collections/pipelinq/ticket?actionId=askQuestion`; the stored ticket carries `occurredAt` "2026-11-25T14:26:02+00:00", the moment of the create, and Mijn vragen (`/mijn/pipelinq/questions`) lists it under Gesteld as "25-11-2026". A client that sends its own `occurredAt` "2000-01-01T00:00:00+00:00" gets the create moment stored instead ("2026-11-25T14:33:22+00:00").
