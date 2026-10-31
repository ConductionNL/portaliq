## ADDED Requirements

### Requirement: A pupil may answer an event for herself, with the number of seats

An `event` MAY allow answers by the learner herself and MAY ask for seats. A signed-in pupil in the event's audience MUST then be able to answer for herself; one answer MUST exist per child per event, whether a guardian or the pupil gave it. With seats asked, an answer MUST carry 1 to the event's maximum per answer, the event MUST count the seats taken, and an answer that would pass the event's capacity MUST be refused in words. After the sign-up deadline answers MUST be refused.

#### Scenario: Noor comes with her father
- **GIVEN** the information evening of 3 November allows pupils to answer and asks seats, capacity 300
- **WHEN** Noor answers yes with 2 seats
- **THEN** one answer exists for Noor with 2 seats, and the seats taken rise by 2
- @e2e exclude spec-only proposal; asserted in PHPUnit and learniq's `tests/e2e/portal-design/vaartveld.spec.ts`

#### Scenario: Her father answers afterwards
- **GIVEN** Noor answered yes with 2 seats
- **WHEN** Erik answers yes with 3 seats for Noor
- **THEN** still one answer exists for Noor, now 3 seats
- @e2e exclude upsert asserted in PHPUnit

### Requirement: A news item may carry the sign-up of its event

A `newsItem` MAY reference an event; the article MUST then show the event's when, where, for whom and deadline as facts and a sign-up card, and a visitor who is not signed in MUST see a sign-in button that returns to the article.

#### Scenario: Signed out
- **GIVEN** the article about the information evening
- **WHEN** an anonymous visitor reads it
- **THEN** the card reads "Je logt eerst in bij Mijn Vaartveld" with a sign-in button
- @e2e exclude spec-only proposal
