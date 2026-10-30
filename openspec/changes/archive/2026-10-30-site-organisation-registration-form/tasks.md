# Tasks: site-organisation-registration-form

- [x] **T1**: `ExampleSiteInstaller` and `ExampleSiteRows` install a site's `forms` (keyed by `externalReference`) before its pages and write each `form` block's `formKey` as the installed form's `formId`; `ExampleSiteCatalogue` drops a form whose `requiresApp` is not installed. Test: PHPUnit `tests/Unit/Service/ExampleSite/ExampleSiteInstallerTest.php`.
- [x] **T2**: `optionLabels` on a form field (`form` schema in `lib/Settings/portaliq_register.json`), shown by `src/site/components/FormBlock.vue`, the stored value sent. Test: `tests/site-form-option-labels.spec.mjs` (the label lives in `optionLabel()`, `src/site/components/forms/fields.js`).
- [x] **T3**: The registration form on the Zuiddrecht site's `/softwarecatalogus` (`lib/Settings/sites/zuiddrecht.json`). Test: `tests/site-software-catalogue.spec.mjs`, `tests/example-site.spec.mjs`.
- [x] **T4**: `DestinationMapping::mappingFor()` drops the campaign fields a destination does not declare. Test: PHPUnit `tests/Unit/Service/Intake/DestinationMappingTest.php`.
