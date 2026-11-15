## ADDED Requirements

### Requirement: A resident works on plans with their contacts (REQ-SPL-001)

The portal SHALL offer "Samenwerken" to a signed-in resident: the plans they own or take part in, with counts Lopend, Actie vereist and Afgerond, and a card per plan with goal, end date, open actions, who shared it, participants and "{n} van {m} acties klaar". A resident MUST only see plans they own or take part in.

#### Scenario: Two running plans
- **WHEN** Sanne owns "Schuldhulp op orde" and Linda Smit shared "Terug naar werk" with her
- **THEN** Samenwerken shows both, "Door u gemaakt" on the first and "Door Linda Smit met u gedeeld" on the second

#### Scenario: Someone else's plan
- **WHEN** a resident who is not a participant requests the plan by its id
- **THEN** the portal refuses it

### Requirement: A plan starts empty or from a template the municipality sets up (REQ-SPL-002)

"Nieuw plan" SHALL offer "Leeg plan" and the published templates of the portal. A template SHALL fill the goal, create its actions with end dates counted from today, and set the end date after its duration. The resident MAY choose participants from approved contacts only. Editors SHALL manage templates in the admin.

#### Scenario: Debt help from a template
- **WHEN** Sanne starts "Schuldhulp op orde" with Mark Jansen on 1 September 2026
- **THEN** the plan has the template's goal, five actions and end date 27 October 2026, and Mark sees it

#### Scenario: Not a contact
- **WHEN** a request adds a participant who is not an approved contact of the owner
- **THEN** the portal refuses it

### Requirement: Participants share the goal, actions, note and file versions (REQ-SPL-003)

Every participant SHALL be able to change the goal, add and edit the plan's actions in the dialog of `personal-action-list`, edit the note and add files. A new file version SHALL keep the earlier version listed under "Eerdere versies". Only the owner SHALL add or remove participants, change the end date, mark the plan done or delete it.

#### Scenario: Mark uploads a new budget plan
- **WHEN** Mark uploads a new Budgetplan.pdf on 6 October
- **THEN** the plan lists "Versie 2, nieuwste" by Mark and keeps "Versie 1" under "Eerdere versies (1)"

#### Scenario: A participant cannot remove the owner
- **WHEN** Mark tries to remove Sanne from the plan
- **THEN** the portal refuses it

### Requirement: A plan near its end date asks for action (REQ-SPL-004)

When a running plan with open actions is 14 days or less from its end date, the plan and its card SHALL show "Actie vereist" and the alert with the date and the number of open actions, and every participant SHALL get one portal notification and one mail. Changing the end date SHALL allow a new reminder for the new date.

#### Scenario: Twelve days left
- **WHEN** "Schuldhulp op orde" ends on 20 October 2026, three actions are open and today is 8 October
- **THEN** the plan shows "Dit plan loopt over 12 dagen af, op 20 oktober 2026" and Sanne and Mark each got one mail

### Requirement: A resident can download a plan as PDF (REQ-SPL-005)

"Download als PDF" SHALL give a PDF with title, goal, end date, participants, actions and the note, in the portal's house style, to every participant.

#### Scenario: For a conversation
- **WHEN** Sanne chooses "Download als PDF" on her plan
- **THEN** she gets a PDF with the goal, the five actions and Mark's note
