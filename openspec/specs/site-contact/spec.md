# site-contact Specification

## Purpose
A resident reaches the organisation from the site: the contact page with its channels and opening hours, a question asked without a case through a contributed create action, and the confirmation that follows.

## Requirements

### Requirement: A resident can ask a question without a case (REQ-SCN-001)

The site SHALL offer a `contactForm` block bound to a contribution create action. The block SHALL show Onderwerp as a choice from the action's subject values and a question field, and SHALL write through the contribution create path with the resident's identity. On success it SHALL say "Wij hebben uw vraag ontvangen. U vindt hem terug bij Mijn vragen." and link to the questions page. Signed out, the block MUST NOT post and SHALL offer the sign-in routes.

#### Scenario: A signed-in resident asks a question
- **WHEN** a signed-in resident picks Onderwerp "Afval" and sends a question
- **THEN** the contribution receives one create with that subject and her identity
- **AND** the question is listed under Mijn vragen

#### Scenario: Signed out
- **WHEN** a visitor without a session opens the form
- **THEN** the block shows "Log in om een vraag te stellen" and posts nothing

### Requirement: The resident gets a confirmation mail (REQ-SCN-002)

After a successful question the portal SHALL mail "Wij hebben uw vraag ontvangen" to the account's verified address, naming the subject and linking to Mijn vragen. The mail MUST NOT contain the question text.

#### Scenario: Confirmation without content
- **WHEN** a resident sends a question about "Afval"
- **THEN** she receives a mail naming "Afval" with a link, and the question text is not in it

### Requirement: The example contact page follows the Contact board (REQ-SCN-003)

The Zuiddrecht example site's `/contact` page SHALL carry the channel cards Bellen, Een bericht sturen and Langskomen, the opening-hours table with Telefonisch and Stadskantoor, the pointer to Mijn zaken for a question about a case, Melding indienen, and the Meer contact links. Its question page `/contact/vraag` SHALL bind the `contactForm` block to pipelinq's `askQuestion`, the create action named by what it does.

#### Scenario: Installing the example site
- **WHEN** an admin runs `occ portaliq:example-site:install zuiddrecht`
- **THEN** `/contact` shows the three channel cards and "Naar Berichten" opens the question form

#### Scenario: The question form finds its action
- **WHEN** a signed-in resident opens `/contact/vraag` on an instance with pipelinq
- **THEN** the block finds pipelinq's `askQuestion` among her contributions and shows the form
- @e2e exclude content binding; covered by `tests/contact-form.spec.mjs`
