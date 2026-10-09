# Tasks: mijn messages follow the boards

## 1. portaliq
- [x] 1.1 `shellSections()` with contacts; `App.vue` reads the contacts with the account.
- [x] 1.2 `withLayoutLabel()`; `App.vue` titles the page with the menu's word.
- [x] 1.3 `MessagesPage.vue`: own title with "Nieuw bericht", notices among conversations, "Antwoorden", dates in words, first contact chosen, no subject, translation folded away.
- [x] 1.4 `caseTitle()` never a uuid; "Zaak" fallback in the case cards.
- [x] 1.5 Tests: `tests/site-look/mijn-messages-follow-the-boards.spec.mjs`.

## 2. learniq (lane LQ)
- [ ] 2.1 Each school portal: menu item `{"item": "messages", "label": "Berichten"}` in its first group, and
  `"routes": {"berichten": "messages"}`; leave `inbox` out of the menu (`leaveOut`) now that its messages stand on the
  conversations page.
- [ ] 2.2 Seed the boards' threads and system messages with a `recordLink` (S): wilgenboom (confirmed conference
  slot, Beterschap voor Sami, approved absence, homework answer), vaartveld (mentor slot, timetable change, grade
  note, Toetsweek), esdoornveen (Petra's return note), academy (Linda, Tom).
- [ ] 2.3 Pupil and student contacts (vaartveld, esdoornveen): the contacts endpoint already answers; make sure the
  `composeLabel` reads in "je" for pupils ("Een bericht aan je mentor").
