# Proposal: guardian-pages-follow-the-wilgenboom-boards

## Why

learniq's guardian pages run on portaliq's site, and four things the wilgenboom boards draw cannot be declared yet. learniq's round-2 lanes listed them as waiting on portaliq:

- [wilgenboom/MijnMenu](https://identity.conduction.nl/screens/board?id=wilgenboom/MijnMenu): the menu reads Mijn Wilgenboom, Mijn kinderen, Regelen, Van school, Uw gegevens, in that order. A portal can lay out its menu by name (`residentMenu.groups`), but a page listed once per child (`perRecord`) had no name, so the children always fell to the end.
- [wilgenboom/Detail](https://identity.conduction.nl/screens/board?id=wilgenboom/Detail): the child's page opens with "Vera ziek of afwezig melden" and "Bericht aan meester Daan". A cta label could only hold `{title}`, so the teacher's name could not be in it.
- [wilgenboom/MijnLijst](https://identity.conduction.nl/screens/board?id=wilgenboom/MijnLijst): under "Goedgekeurd" the row says who decided and when, "Meester Daan, 1 oktober 8:20 uur". A rows display had one line under the status and no moment.
- [wilgenboom/MobielDetail](https://identity.conduction.nl/screens/board?id=wilgenboom/MobielDetail): the absence form asks "Wie is er afwezig?" with a card per child ("Vera" over "Groep 7 · Meester Daan") and "Wanneer?" with "Vandaag" over "Maandag 5 oktober". Choice cards had one line, and the named days read "Vandaag, maandag 5 oktober".

## What changes

- A `perRecord` page carries its `app:page` name in the menu, so a portal's layout places all its rows (the children) in the group that names it.
- A cta label MAY name any field of the open record (`{teacherName}`), and MAY declare `labelWhenEmpty` for a record where that field is empty.
- A `rows` display MAY declare `statusNoteAtField`: the line under the status reads "{who}, {moment}", with "vandaag" for today.
- A `collection` options provider MAY declare `descriptionField`; a choice card shows it as a second line. Named days read as a name over the date: "Vandaag", "Morgen", or the date alone for a later day.

## Capabilities

### Modified capabilities

- `site-resident-menu`, `site-forms`, `portal-contribution-contract`: ADDED requirements, all opt-in. A contribution that declares none of the new keys renders as before, except the named-day cards, which now carry the date as a second line.

## Impact

- Backend: `lib/Contribution/ActionOptionsNormaliser.php`, `CtaBlockNormaliser.php`, `DisplayKeys.php`; register 0.105.0 (portalPage 0.9.0: `statusNoteAtField`, `labelWhenEmpty`, additive).
- Site: `src/site/lib/residentMenu.js`, `src/site/components/forms/{ChoiceCards,DateChoices}.vue`, `fields.js`, `src/site/components/mijn/{DateRows.vue,displays.js,rows.js,template.js}`, `src/site/pages/collections/ContributionPage.vue`, `src/shared/portalApi.js`.
- Consumer: learniq declares the layout, the labels, `statusNoteAtField: decidedAt` and `descriptionField: groupLabel` in its own repo.

## Not in this change

- Opening the message form with the child's teacher already chosen from "Bericht aan meester Daan". The cta opens the messages page; the guardian picks the teacher there.
- "de hele dag" after today's date, which the board draws on the first day card only.
