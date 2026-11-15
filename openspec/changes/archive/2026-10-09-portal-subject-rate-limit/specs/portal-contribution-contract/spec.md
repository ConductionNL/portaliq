## ADDED Requirements

### Requirement: A signed-in portal session must be rate limited per subject

A collection read with a portal session MUST count against a limit per subject, with room for
the blocks of several pages a minute, and never against a limit shared by everyone behind the
same IP address. A read without a session MUST count against a tight limit per IP address. Over
either limit the portal MUST answer 429.

#### Scenario: A guardian opens four pages in a minute
@e2e exclude PHPUnit PortalRateLimitTest; checked live on the proof instance
- GIVEN a guardian signed in to the portal
- WHEN she opens four pages of twenty blocks within one minute
- THEN every collection read is answered, none with 429

#### Scenario: Calls without a session
@e2e exclude PHPUnit PortalRateLimitTest
- GIVEN calls without a portal session from one IP address
- WHEN more than 60 arrive within a minute
- THEN the portal answers 429

### Requirement: A collection that could not be read must say so

A table or figure block whose collection read failed MUST say "De inhoud kon niet worden
geladen." with a button "Opnieuw proberen" that reads it again, and MUST NOT draw an empty list.

#### Scenario: A read answered 429
@e2e exclude Node: tests/portal-subject-rate-limit.spec.mjs
- GIVEN the homework collection read failed
- WHEN the page renders
- THEN the block shows the sentence and the retry button, and no empty table
