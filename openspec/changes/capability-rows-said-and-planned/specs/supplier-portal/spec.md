## ADDED Requirements

### Requirement: Staff actions on portal accounts and sessions are in the trail (REQ-RSP-013)

The portal audit trail SHALL also record what staff did to portal subjects: withdrawing an account, moving an account to another audience, revoking sessions and sending an emergency message. Each row SHALL name the staff member and the subject.

#### Scenario: An administrator revokes an organisation's sessions
- **WHEN** an administrator revokes every session of one organisation
- **THEN** the audit trail holds a row naming that administrator, the organisation and the revocation
