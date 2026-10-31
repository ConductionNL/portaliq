## MODIFIED Requirements

### Requirement: The example contact page follows the Contact board (REQ-SCN-003)

The Zuiddrecht example site's `/contact` page SHALL carry the channel cards Bellen, Een bericht sturen and Langskomen, the opening-hours table with Telefonisch and Stadskantoor, the pointer to Mijn zaken for a question about a case, Melding indienen, and the Meer contact links. Its question page `/contact/vraag` SHALL bind the `contactForm` block to pipelinq's `askQuestion`, the create action named by what it does.

#### Scenario: Installing the example site
- **WHEN** an admin runs `occ portaliq:example-site:install zuiddrecht`
- **THEN** `/contact` shows the three channel cards and "Naar Berichten" opens the question form

#### Scenario: The question form finds its action
- **WHEN** a signed-in resident opens `/contact/vraag` on an instance with pipelinq
- **THEN** the block finds pipelinq's `askQuestion` among her contributions and shows the form
- @e2e exclude content binding; covered by `tests/contact-form.spec.mjs`
