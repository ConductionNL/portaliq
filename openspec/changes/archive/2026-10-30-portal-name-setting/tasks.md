# Tasks: portal-name-setting

### Task 1: The portal has a name other apps can read
- **spec_ref**: `openspec/changes/portal-name-setting/specs/portal-name/spec.md#requirement-the-portal-has-a-name-other-apps-can-read`
- **files**: `lib/Service/PortalNameSetting.php`
- [x] Implement: `PortalNameSetting` over app config `portalName`

### Task 2: An installed site names the portal when none is set
- **spec_ref**: `openspec/changes/portal-name-setting/specs/portal-name/spec.md#requirement-an-installed-site-names-the-portal-when-none-is-set`
- **files**: `lib/Service/ExampleSite/ExampleSiteInstaller.php`
- [x] Implement: the install calls `fillFrom` with the site's portal
- [x] Test: `tests/Unit/Service/ExampleSite/ExampleSiteInstallerTest.php`
