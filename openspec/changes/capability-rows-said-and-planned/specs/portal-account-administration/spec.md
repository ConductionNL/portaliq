## ADDED Requirements

### Requirement: Staff withdraw an account issued by mistake (REQ-RSP-001)

Staff who may manage portal accounts SHALL be able to withdraw an account that is still pending, with a reason. A withdrawn account SHALL no longer sign in, and its invitation or activation link SHALL stop working.

#### Scenario: Staff withdraw a pending account
- **GIVEN** a pending portal account issued to the wrong e-mail address
- **WHEN** a staff member chooses "Withdraw this account" and gives the reason "wrong address"
- **THEN** the account is withdrawn with that reason, and its activation link answers that it no longer works

#### Scenario: An active account offers no withdraw
- **WHEN** a staff member opens an account that is already active
- **THEN** no "Withdraw this account" action is offered
