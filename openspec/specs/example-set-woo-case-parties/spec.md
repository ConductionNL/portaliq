# example-set-woo-case-parties Specification

## Purpose
The Zuiddrecht example set: the parties of the Lindelaan Woo case, and who else may open it.

## Requirements

### Requirement: The Lindelaan Woo case carries its parties

The Zuiddrecht fragment SHALL put five parties on `woo-fietspad`, as board DqZaakBetrokkenen draws them. Each is a party link in the Woo case type's role, with the details line and the period the board draws, and a dossiq role record whose description is the line Rollen draws after the role word (Sanne none, Van Wijk "KvK 00001005", J. van Wijk "namens Aannemersbedrijf Van Wijk B.V. · Awb 2:1", the association "zonder account · via de balie", Lars "Mobiliteit en verkeer"):
- Lars de Koning as Adviseur, a colleague: "Mobiliteit en verkeer · lars.dekoning@zuiddrecht.example", ending 14 days after the board's day;
- Sanne de Vries as primary Verzoeker: "Lindelaan 14 · ingelogd met DigiD", from the case's start;
- Aannemersbedrijf Van Wijk B.V. as Belanghebbende: "KvK 00001005 · contactpersoon J. van Wijk", from 11 days before the board's day;
- J. van Wijk as Gemachtigde acting for Van Wijk (role record `representedParty`): "Machtiging namens Aannemersbedrijf Van Wijk B.V. · Awb 2:1", ending 75 days after the board's day;
- Bewonersvereniging Lindelaan as Melder: "Melder zonder account · via de balie", from the case's start.

The board's day is the case's start plus 14 days. A link that ends shows only its end, so it has no start: the set names its start as null, which also clears a start an earlier install wrote. Names, numbers and addresses come from the cast.

#### Scenario: The people-involved tab
- **GIVEN** the set is installed
- **WHEN** a handler opens the Lindelaan case's people
- **THEN** Partijen SHALL list the five parties by role, each with its details line and its period ("Tot", "Vanaf")
- **AND** Rollen SHALL list them with Sanne first as primary, J. van Wijk with his mandate's end date and the residents' association with its indicator
- **AND** each Rollen row SHALL read its role record's description after the role word, not the Partijen details line

### Requirement: The Lindelaan Woo case has a read-only link for Sanne

The Zuiddrecht fragment SHALL declare one dossiq `caseShare` record of type `link` on `woo-fietspad`: labelled with Sanne de Vries's name, capabilities `read`, made by Pieter Jansen, active, ending 29 days after the board's day at 23:59.

The link SHALL be a real OpenRegister access link: the fragment declares it under `accessLinks` (openregister change `example-set-access-links-and-grants`), read only, minted by Pieter Jansen with the same label and end, and a change SHALL point the `caseShare` record at it through `{{accessLink:woo-fietspad-link-sanne.id|uuid|url}}`. Pieter Jansen owns the case, so the link and sharing guards admit him as they would for his own click.

#### Scenario: The Toegang card
- **GIVEN** the set is installed
- **WHEN** a handler the case is assigned to opens the case's people
- **THEN** Toegang SHALL list "Link voor Sanne de Vries", "Alleen lezen, zonder account", with its end date as a pill
- **AND** the row's revoke SHALL revoke the minted link, not answer its error text

### Requirement: The Lindelaan Woo case is shared with Juridische zaken

The Zuiddrecht fragment SHALL declare one grant on `woo-fietspad` under `grants`: the group Juridische zaken, read only, shared by Pieter Jansen 11 days before the board's day ("6 okt"), ending 14 days after it ("Tot 31 okt").

#### Scenario: The Toegang card's group row
- **GIVEN** the set is installed
- **WHEN** a handler the case is assigned to opens the case's people
- **THEN** Toegang SHALL list "Juridische zaken" with the origin line "gedeeld door Pieter Jansen"
