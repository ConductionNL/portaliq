# Tasks: public-detail-page-for-a-provider-item

- [x] 1. Contract: `publicDetail` on a contribution (route, facts, sections, dates, documents); normaliser keeps what fits. The contract is the provider method `getPublicDetail` and the index item's `slug`, held to shape by `PublicDetailShape`; the route is the CMS page the widget is placed on, so no manifest key was added.
  - PHPUnit for the normaliser (`PublicDetailTest`)
- [x] 2. Public route and controller: anonymous, cached by audience, 404 for an unknown or non-public item.
- [x] 3. Site page: facts list, sections, date cards with places and a selected state, documents, secondary link.
  - `node --test tests/site-public-detail.spec.mjs`
- [ ] 4. `requiresSignIn` actions: sign-in button, return to the page with values kept, refusal in words for the wrong audience. — partial: the sign-in button and the kept date and count are done (`tests/site-public-detail.spec.mjs`); submitting the app's action from the page and the wrong-audience refusal are not run: a public page has no route to an app action, and the audience check needs learniq.
- [ ] 5. learniq declares the course and programme detail (learniq follow-up on `portal-public-index`). — not run: needs learniq
