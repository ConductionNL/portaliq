---
kind: code
---

# Proposal: contact-page-question-form-and-not-found

## Why

Two pages send a resident away empty-handed. The not-found page says "Page not found" and stops: no search, no way home, no way to report the broken link. The contact page lists a phone number and opening hours, but a resident with a question that is not about a case has nowhere to type it. Pipelinq takes such a question from a business user ("Een verzoek indienen namens uw organisatie"), not from a resident on the public site.

Open Inwoner has a general contact form with a subject, registered in the klantcontact system (`src/open_inwoner/openklant/views/contactform.py:36`, subject at `:162`). NL Portal's not-found page points to home and to contact (`NoMatchPage.tsx:16`). The Zuiddrecht boards **Contact** and **NietGevonden** draw both pages.

## What changes

- **The contact page follows the Contact board.** Channel cards Bellen, Een bericht sturen and Langskomen, the opening-hours table with Telefonisch and Stadskantoor, the pointer "Een vraag over uw eigen zaak? Open de zaak bij Mijn zaken", Melding indienen, and the "Meer contact" links. It is example content of the Zuiddrecht site, built from existing widgets plus one new block.
- **A question form without a case.** A new site block `contactForm` posts to a contribution's create action for questions. The resident picks an Onderwerp from the action's subject list, types the question, and finds it back under "Mijn vragen". Signed in, the question carries their identity; signed out, the block sends them to sign in first.
- **A confirmation mail.** The organisation's klantcontact app answers through its own notice; portaliq sends "Wij hebben uw vraag ontvangen" with the template the mail templates screen lists as "Bevestiging contactformulier".
- **A not-found page with a way on.** "Pagina niet gevonden", "Foutcode 404", a search box, links to the homepage, Mijn Zuiddrecht and Contact, and "Kwam u hier via een link op onze website? Laat het ons weten via Contact."

## Rows covered

- `int-general-contact-form`, `site-404-contact` (decision 101); `site-contact-page` (decision 102, built, the example page follows the board here).

## Cross-repo

Pipelinq's question action today serves the `client` audience. A resident version with a subject list is pipelinq's to declare (a `citizen` create action on `ticket` with `ticketType: question`). T07 asks for it.

## Out of scope

- Anonymous questions. A question without sign-in is the report flow (report-without-an-account).
- Questions about a case. They stay on the case page (`cmp-act-ask-question-case`).
