## ADDED Requirements

### Requirement: An administrator reads the rights per group (REQ-ARP-001)

The app SHALL offer administrators a page that lists every group holding at least one right, with its name, its number of members and the rights it holds, read from an admin-only route whose check runs before anything is read. Administrators SHALL NOT be listed as a group.

#### Scenario: Two teams with rights
- **GIVEN** Servicedesk (5 members) may manage portal accounts and Communicatie (3 members) may manage portal accounts and write news
- **WHEN** an administrator opens Beheerdersrechten
- **THEN** the list shows Communicatie with 3 members and two rights, and Servicedesk with 5 members and one right
