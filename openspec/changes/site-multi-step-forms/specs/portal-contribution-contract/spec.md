## ADDED Requirements

### Requirement: A create action MAY run in steps with a review, a draft and a confirmation (REQ-SMF-020)

The contribution contract MUST accept on a create action: `steps` (`[{ id, title, description?, fields[], review? }]`), `draft` (`{ retentionDays }`, an integer 1 to 90) and `confirmation` (`{ title, body?, next? }`). A step MUST be kept only when every field it names is one of the action's fields. A step with `review: true` MUST carry no fields and MUST be rendered as the review of REQ-SMF-011. Fields in no step MUST go in a last step of their own. The site MUST render such an action with the step flow of REQ-SMF-010 and REQ-SMF-011.

#### Scenario: The Woo request in four steps
- GIVEN dossiq's `startWooVerzoekAlgemeen` declares steps "Uw vraag", "Periode en documenten", "Uw gegevens" and a review step "Controleren en versturen"
- WHEN the resident opens the action on a site page
- THEN the form shows "Stap 1 van 4: uw vraag" and a progress list of four steps

#### Scenario: A step naming a field the action lacks is dropped
- GIVEN a step whose `fields` include `iban` and the action has no field `iban`
- WHEN the contribution is normalised
- THEN that step is dropped and its other fields go to the last step

### Requirement: A draft of a create action MUST stay with portaliq and the resident (REQ-SMF-021)

For a create action with `draft`, the site MUST offer "Opslaan en later verdergaan" to a signed-in resident. Portaliq MUST store the visible answers and the step reached as one draft per subject, contribution and action, readable only by that subject. Portaliq MUST NOT send a draft to the contributing app. It MUST delete the draft when the action is sent and after `retentionDays`. A signed-out visitor MUST NOT get a draft. File answers MUST NOT be kept in a draft.

#### Scenario: Sanne continues her Woo request
- GIVEN Sanne saved a Woo request draft at step 2 on 2 October 2026 and the action keeps drafts 30 days
- WHEN she opens the action on 5 October 2026
- THEN step 2 opens with her answers
- AND dossiq holds no case for it

#### Scenario: Another resident cannot read the draft
- GIVEN Sanne's draft exists
- WHEN another signed-in resident asks for drafts of the same action
- THEN they receive none

#### Scenario: A draft expires
- GIVEN a draft saved 31 days ago on an action with `retentionDays: 30`
- WHEN the purge job runs
- THEN the draft is gone

### Requirement: A create action MAY word its own confirmation (REQ-SMF-022)

A create action MAY declare `confirmation` with `title`, `body` and `next`. The site MUST fill `{identifier}` and `{deadline}` from the action's answer. It MUST leave out a sentence whose placeholder has no value. Without `confirmation` the site MUST show `successMessage` as before.

#### Scenario: No deadline yet
- GIVEN the confirmation body "Uw zaaknummer is {identifier}. U krijgt uiterlijk {deadline} antwoord."
- AND the answer carries `identifier: 2026-0003` and no `deadline`
- WHEN the confirmation renders
- THEN it reads "Uw zaaknummer is 2026-0003." and nothing about a deadline
