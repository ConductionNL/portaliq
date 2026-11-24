# Proposal: guardian-message-to-and-whole-day

## Why

Two gaps left by `guardian-pages-follow-the-wilgenboom-boards`, both drawn on the wilgenboom boards:

- [wilgenboom/Detail](https://identity.conduction.nl/screens/board?id=wilgenboom/Detail): "Bericht aan meester Daan" opens the messages page, but the guardian then has to pick meester Daan again in the "Aan" field. The button already names him.
- [wilgenboom/MobielDetail](https://identity.conduction.nl/screens/board?id=wilgenboom/MobielDetail): the first day card of the absence form reads "Vandaag" over "Maandag 5 oktober, de hele dag". The site writes "Maandag 5 oktober" only, so a parent cannot tell a whole day from a few lessons.

## What changes

- **A cta may name whom its message goes to.** `messageTo` on a cta block names the field of the open record that holds the staff member ("teacherRef"). Opening the cta keeps the open record and that staff member for the tab; the messages page takes it once and opens its form with that contact chosen and the form in view. Without a match it chooses the record's only contact, or no one. Register 0.108.0, portalPage 0.10.0.
- **Today reads as the whole day.** The first named day card reads its date followed by ", de hele dag" (English ", the whole day"). Tomorrow and later days are unchanged.

## Capabilities

### Modified capabilities

- `portal-contribution-contract`: ADDED requirement for `messageTo`.
- `site-forms`: ADDED requirement for the whole-day words on today's card.

## Impact

- Backend: `lib/Contribution/CtaBlockNormaliser.php` keeps `messageTo`; the register's portalPage block.
- Site: `src/site/pages/inbox/conversations.js` (`keepMessageTo`, `takeMessageTo`, `chosenContact`), `MessagesPage.vue`, `ContributionPage.vue` (`keepMessageTarget`), `src/site/components/forms/fields.js` (`namedDays`), `DateChoices.vue`, `SchemaField.vue`, `src/site/pages/c/strings.js`.
- learniq: add `messageTo: "teacherRef"` (or the field its learner rows carry) to the child page's "Bericht aan {teacherName}" cta. Tracked in learniq.
