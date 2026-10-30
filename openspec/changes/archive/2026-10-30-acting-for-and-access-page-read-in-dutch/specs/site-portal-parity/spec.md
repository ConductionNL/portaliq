## ADDED Requirements

### Requirement: The account pages speak the page's language

Every account page (my cases, access to cases, my details, my account, contacts, theme, plans) MUST translate a string the shell's translator does not know from its own strings in the page's language. The shell's answer MUST win where it knows the key. These strings MUST load with the page, not in the site entry.

#### Scenario: Toegang tot zaken in Dutch
- GIVEN a Dutch portal and a signed-in resident
- WHEN they open "Toegang tot zaken"
- THEN the introduction reads "Wie zaken voor u mag regelen, en voor wie u zaken regelt."
- AND the DigiD note reads "Machtigen via DigiD Machtigen"
- @e2e exclude pinned by `tests/account-pages-language.spec.mjs`; the live check on the rehearsal follows the merge

### Requirement: A mandate is named by its party

A mandate's `label` MUST be the name of the party it acts for, as the holder reads it, because the site says "U regelt nu zaken voor {label}". Seeded and demo mandates MUST carry such a name, not a sentence such as "Namens Henk Bakker".

#### Scenario: Linda acts for her father
- GIVEN Linda Bakker holds the example mandate for Henk Bakker
- WHEN she chooses to act for him
- THEN the bar reads "U regelt nu zaken voor Henk Bakker"
- @e2e exclude pinned by `tests/account-pages-language.spec.mjs` "an example mandate is named by its party, not by a sentence"
