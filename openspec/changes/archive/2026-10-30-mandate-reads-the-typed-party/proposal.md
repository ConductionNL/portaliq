---
kind: code
---

# Proposal: mandate-reads-the-typed-party

## Why

In the Zuiddrecht live test on 14 October 2026, Linda opened "Mijn zaken" while acting for her father Henk and saw no cases. dossiq stores the party a portal-filed case belongs to in the typed form a mandate names it in (`subject:<uid>`). `PortalCaseListReader::readParty` asked only for the bare form (`<uid>`), which `PortalMandateService::describe()` hands it, so none of Henk's cases matched.

## What changes

- `PortalCaseListReader::readParty` reads a collection by the typed party and by the bare party, and lists a case found both ways once (by its id, flat or in `@self`).
- The bare read stays, so a case written before the typed form keeps opening.

The frontend half (the "Zaken van" choice on Mijn zaken) is its own change, `mijn-zaken-acts-for-from-the-list`.
