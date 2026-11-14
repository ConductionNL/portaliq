# Tasks: event-sign-up-by-a-pupil-with-seats

- [x] 1. Register: `event.rsvpBy`, `askSeats`, `maxSeatsPerAnswer`, `capacity`, `signupDeadline`; RSVP `seats`; `newsItem.eventRef`.
- [x] 2. RSVP service: the learner herself, one answer per child per event, seats against capacity, deadline.
  - PHPUnit for the upsert, capacity and deadline (`EventRsvpServiceTest`, `EventGuardianControllerTest`)
- [ ] 3. Article: event facts and sign-up card, sign-in return to the article. — partial: facts, the sign-in line with its return to the article, the closed sentence and the signed-in button (to the resident area) are done (`node --test tests/site-news-event.spec.mjs`); answering inside the article is not run: the site shell does not hold the resident's child references yet.
- [x] 4. i18n en and nl; staff News screen picks the event. — i18n (schema strings, article words, the picker's two labels) and the News endpoint's `eventRef` are done; staff list events through `GET /api/events` (`EventController::index`, the `portal.manage-event` guard) and pick one in `NewsItemDialog`.
  - PHPUnit `EventControllerTest`; `node --test tests/news-authoring-steps.spec.mjs tests/news-authoring.spec.mjs`
- [ ] 5. learniq's vo example portal publishes the evening with its event. — not run: needs learniq
