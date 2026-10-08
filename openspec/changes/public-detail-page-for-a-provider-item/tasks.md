# Tasks: public-detail-page-for-a-provider-item

- [ ] 1. Contract: `publicDetail` on a contribution (route, facts, sections, dates, documents); normaliser keeps what fits.
  - PHPUnit for the normaliser
- [ ] 2. Public route and controller: anonymous, cached by audience, 404 for an unknown or non-public item.
- [ ] 3. Site page: facts list, sections, date cards with places and a selected state, documents, secondary link.
  - `node --test tests/site-public-detail.spec.mjs`
- [ ] 4. `requiresSignIn` actions: sign-in button, return to the page with values kept, refusal in words for the wrong audience.
- [ ] 5. learniq declares the course and programme detail (learniq follow-up on `portal-public-index`).
