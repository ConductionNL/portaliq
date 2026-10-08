# Tasks: mail-templates-admin-screen

- [ ] **T01**: `lib/Settings/portaliq_register.json`: schemas `portalMailTemplate` and `portalMailLog` per design.md "Data"; register bump; import and grep for `PARTIAL IMPORT` (REQ-PMT-001, REQ-PMT-003)
- [ ] **T02**: `lib/Service/Mail/MailTemplateRenderer.php`: template keys, default texts moved from `PortalIdentityMailer` and the notification and task jobs, declared variables, override lookup per portal; PHPUnit for default, override, unknown variable (REQ-PMT-001, REQ-PMT-002)
- [ ] **T03**: `PortalIdentityMailer`, `NotificationDispatchJob`, `PortalTaskDeliveryJob` render through the renderer and write a `portalMailLog` row per send (REQ-PMT-003)
- [ ] **T04**: Resend and test mail endpoints, admin only (`#[AuthorizedAdminSetting]`), routes in `appinfo/routes.php`, rate limit on resend; PHPUnit (REQ-PMT-003, REQ-PMT-004)
- [ ] **T05**: `lib/BackgroundJob/MailLogRetentionJob.php` (daily, 90 days), registered in `appinfo/info.xml` (REQ-PMT-003)
- [ ] **T06**: `src/manifest.json` page `MailTemplates` per the PtMailsjablonen board: template table, editor with variable chips, preview, reset, send log with tabs, CSV export (REQ-PMT-001, REQ-PMT-003)
- [ ] **T07**: Playwright: edit, preview, test mail, reset; the log shows a failed mail with resend
- [ ] **T08**: Live check against the PtMailsjablonen board; screenshots in the build PR
