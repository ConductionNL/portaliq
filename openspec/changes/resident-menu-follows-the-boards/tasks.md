# Tasks: resident menu follows the boards

## 1. portaliq
- [x] 1.1 Portal schema 0.15.0, register 0.72.0: labelled items, `person`, `routes`; schema strings in English and Dutch.
- [x] 1.2 `PortalResidentMenu` projects them (PHPUnit `PortalResidentMenuTest`).
- [x] 1.3 `residentMenuGroups()`, `menuPerson()`, `menuSubline()`, `aliasedRoute()`, `loadPerRecordRows()` extra collections.
- [x] 1.4 `accountRedirect()` opens a second address, else the home.
- [x] 1.5 `ResidentMenu.vue` person block and board look; `AccountArea.vue` and `App.vue` pass it on.
- [x] 1.6 Tests: `tests/site-look/resident-menu-follows-the-boards.spec.mjs`.
- [x] 1.7 `overview` stands for the home page; menu row height, bar and current colour as set tokens in `css/site-theme.css`.
- [ ] 1.8 thematiq: the four school sets name the three menu tokens (thematiq PR).

## 2. learniq declares (lane LQ, per portal record in `lib/Settings/portals/<set>.json`)
- [ ] 2.1 po.json (De Wilgenboom): `residentMenu.groups` =
  `[{"title":"Mijn Wilgenboom","items":["overview",{"item":"messages","label":"Berichten"}]},
  {"title":"Mijn kinderen","items":["learniq:<child page>"]},
  {"title":"Regelen","items":["learniq:<absence page>","learniq:<conference page>","learniq:<calendar page>"]},
  {"title":"Van school","items":["news","learniq:<documents page>"]},
  {"title":"Uw gegevens","items":["details","account"]}]`, plus `"routes": {"berichten": "messages"}`.
  The child page's `records.titleFields` should be `["givenName"]` (board: "Vera", not "Vera Hulstkamp").
- [ ] 2.2 vo.json (Vaartveld, pupil): groups "Mijn Vaartveld" (overview, messages as "Berichten"), "School"
  (timetable, homework and tests, grades, absence), "Regelen" (mentor talk, subjects and profile, documents),
  "Account" (`{"item":"details","label":"Mijn gegevens"}`); `"person": {"collection":"learniq:<pupil row>","fields":["<level>","<class>"]}`.
- [ ] 2.3 mbo.json (Esdoornveen, student): "Mijn Esdoornveen" (overview, Berichten), "Mijn opleiding" (Rooster,
  BPV en uren, Beoordelingen, Examens, Keuzedelen), "Regelen" (Ziek melden, Gesprekken, Documenten), "Account" (Mijn gegevens).
- [ ] 2.4 training.json (academy, employer): "Mijn academie" (overview, Berichten), "Cursussen" (Inschrijvingen,
  Medewerkers, Certificaten), "Administratie" (Facturen, Bedrijfsgegevens), "Account" (Mijn gegevens);
  `"person": {"collection":"learniq:employerOverview","fields":["<employee count text>","<sign-in text>"]}`.
- [ ] 2.5 Badges: `badge: {collection, label}` on the grades page (new grades) and the conference page, as the boards count them.
