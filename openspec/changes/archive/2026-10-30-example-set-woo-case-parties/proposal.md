# Proposal: example-set-woo-case-parties

## Why

DqZaakBetrokkenen draws the parties of the Lindelaan Woo case by role, from OpenRegister's party model:
- Sanne de Vries as primary requester;
- Aannemersbedrijf Van Wijk B.V. as interested party;
- J. van Wijk as its authorised representative, with a mandate that ends;
- Bewonersvereniging Lindelaan as a reporter without an account.

The case had none of them.

## What changes

- **Four dossiq `role` records** on `woo-fietspad`, one for each party, in the Woo case type's role types. J. van Wijk's record names Van Wijk as the party he acts for (`representedParty`).
- **Four links in OpenRegister's `parties` part**, one per party, each pointing at its role record's participant and with its period. Sanne's is primary.
- The records come from:
  - openregister: `parties` part (#4766) and cast (`feat/zuiddrecht-cast-woo-parties`);
  - dossiq: role types and party schemas (#4155) and contacts (`fix/exset-woo-party-contacts`).

The residents' association's indicator "Meerdere meldingen" lives on its company record in dossiq's fragment.
