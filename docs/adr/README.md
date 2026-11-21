# Architecture decision records

Numbered records of decisions this repository has already made, in MADR style
(status, context, decision, consequences). Each record cites the file in this
repository where the decision was first written down.

| ADR | Decision |
| --- | --- |
| [0001](0001-generic-oidc-relying-party.md) | External sign-in is a generic OIDC relying party behind an identity broker |
| [0002](0002-declarative-contribution-contract.md) | Apps contribute portal surfaces as manifest data; enforcement stays in portaliq code |
| [0003](0003-signed-subject-assertion-for-forwarded-actions.md) | Endpoint actions are forwarded server to server with a signed subject assertion |
| [0004](0004-ownership-verified-before-write.md) | Single-object reads and writes re-verify ownership and never reveal which ids exist |
| [0005](0005-field-projection-after-verification.md) | Field projection runs in the reader, after row verification |
| [0006](0006-integration-leaves-internal-only.md) | Integration leaves are for staff only and never reach a portal response |

`openspec/architecture/` holds a copy of an organisation-wide record (ADR-023,
action authorization). New records for decisions specific to this app go here.
