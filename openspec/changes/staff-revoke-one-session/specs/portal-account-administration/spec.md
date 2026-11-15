## ADDED Requirements

### Requirement: Staff revoke one portal session (REQ-ISA-010)

An instance administrator SHALL be able to revoke one portal session by its token id. The permission check SHALL run before the session is read or written. Only that session SHALL stop working; other sessions of the same account and organisation SHALL keep working. The revocation SHALL be recorded as `admin-revoke` naming the administrator. A token id no live session carries SHALL answer not found and record nothing. The Sessions list SHALL offer "Sessie intrekken" on a live session and "Naar het account" on every session that names its account.

#### Scenario: A lost phone
- **WHEN** an administrator chooses "Sessie intrekken" on Sanne's phone session and confirms
- **THEN** that bearer is refused from then on
- **AND** Sanne's laptop session still works
- **AND** the history shows `admin-revoke` with the administrator's name

#### Scenario: Not an administrator
- **WHEN** a user who is not an instance administrator posts to the revoke route
- **THEN** Nextcloud refuses the request before the session is read

#### Scenario: From the session to its account
- **WHEN** a clerk chooses "Naar het account" on a session row
- **THEN** the account the session was issued for opens
