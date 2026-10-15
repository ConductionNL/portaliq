## ADDED Requirements

### Requirement: An editor MUST be able to place a public record list on a site page (REQ-SCR-005)

The widget palette MUST offer a public records block whose configuration names one contributed record list from the anonymous aggregate. On a public page without `?record`, the block MUST show the list's label, a search field and a link per entry with its title and subtitle. A configured list that no longer exists MUST render nothing on the public page and a notice in the editor.

#### Scenario: The communications officer builds the council page
- GIVEN decidiq contributes `memberVotingRecords`
- WHEN the editor adds the public records block to the page "Hoe stemden de raadsleden" and picks that list
- THEN a visitor sees every public council member with role and party, and can search by name
- e2e: `tests/e2e/site-member-voting-record.spec.ts`

### Requirement: A visitor MUST read one member's voting record without signing in (REQ-SCR-006)

With `?record=<id>` the block MUST show the record on the same page, laid out as the PtStemgedrag board: the title as heading (inside a sentence the page may write, "Stemgedrag van {title}"), the rows as a table in the provider's column order with a link on the subject when the row carries one, and the provider's note; beside them a side card with the title's initials, the title, the subtitle, the summary as figure cards, a link back to the list and the links the page names. A page MAY declare filters on the record's own columns (each a choice of the values the rows hold, applied with one button), a count line, and a page size; the rows then show one page at a time with a pager ("1 tot 5 van 48", page numbers, Vorige and Volgende), and a filter starts again at the first page. The list and the record MUST also render without JavaScript. An id the list does not hold MUST show "Dit overzicht bestaat niet (meer)." and answer 404.

#### Scenario: A resident checks how her councillor voted
- GIVEN Sanne Mulder voted for "Groen dak op het stadhuis" in a public round
- WHEN a resident opens Sanne Mulder on "Hoe stemden de raadsleden"
- THEN she sees the cards Deelname, Voor, Tegen and Onthouden and a row with Datum, Onderwerp "Groen dak op het stadhuis", Stem "Voor" and Uitslag "Aangenomen"
- e2e: `tests/e2e/site-member-voting-record.spec.ts`

#### Scenario: A resident narrows a voting record to one meeting
- GIVEN the page declares the filters Vergadering and Stem and 5 rows per page, and Joost van Dalen has 48 public votes
- WHEN a resident opens Joost van Dalen
- THEN she reads "Stemgedrag van Joost van Dalen", the first 5 votes, "1 tot 5 van 48" with the pages, and a side card with "JD", his party, his figures and "Alle raadsleden"
- AND after choosing the meeting of 1 oktober 2026 and "Toon stemmingen" she sees only that meeting's votes, from the first page
- e2e: `tests/e2e/site-member-voting-record.spec.ts`

#### Scenario: A stale link
- GIVEN a link to a person who is no longer listed
- WHEN a visitor opens it
- THEN the page says "Dit overzicht bestaat niet (meer)." and answers 404
- @e2e exclude pinned by `PublicRecordControllerTest::testAnUnlistedRecordIsNotFound`

### Requirement: A named reader MUST find confidential papers in the resident area (REQ-SCR-007)

A contributed collection with `documents` MUST show its rows on its page under `/mijn` and, on a row's detail, a Documenten section with each listed document and a Downloaden action. An object with no listed documents MUST say there are no papers.

#### Scenario: Pieter Bos reads the papers for the closed session
- GIVEN Pieter Bos signed in with DigiD at substantial and is named on "Grondaankoop Lindelaan"
- WHEN he opens "Vertrouwelijke stukken" and then the item
- THEN he sees the meeting and date, and the two papers each with Downloaden
- e2e: `tests/e2e/confidential-papers.spec.ts`
