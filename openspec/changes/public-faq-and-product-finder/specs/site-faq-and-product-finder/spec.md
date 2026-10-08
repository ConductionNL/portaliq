## ADDED Requirements

### Requirement: A FAQ entry is written once and shown on every page it belongs to (REQ-FPF-001)

The portal SHALL store FAQ entries with question, answer, the pages they belong to, a topic and an order. A `faqList` block SHALL show the published entries of its page, or of its topic, as an accordion. A page listing all published entries grouped by topic SHALL be possible with the same block. An unpublished entry MUST NOT appear on the public site.

#### Scenario: One answer on two pages
- **WHEN** an editor links the entry "Hoeveel vergunningen per adres?" to the topic page Parkeren en verkeer and the product page Parkeervergunning bewoners
- **THEN** both pages show it under Veelgestelde vragen

#### Scenario: A draft answer
- **WHEN** an entry has status draft
- **THEN** no public page shows it

### Requirement: A product finder narrows the products with yes or no questions (REQ-FPF-002)

A `productFinder` block SHALL show one question at a time with its position, the earlier answers as chips that go back to their question, Ja and Nee, Vorige vraag and Opnieuw beginnen. Each answer SHALL rule out the products the question lists for it, and the block SHALL show the remaining products with their count and fold the ruled-out ones. A question that cannot change the remaining set SHALL be skipped.

#### Scenario: Living outside the centre
- **WHEN** a resident answers Nee to "Woont u in de binnenstad?" and that answer rules out eight of twelve products
- **THEN** the block reads "Nog 4 van de 12 producten passen bij uw antwoorden" and lists "Vallen af door uw antwoorden (8)" folded

#### Scenario: Changing an earlier answer
- **WHEN** she picks the chip of question 1 and changes her answer
- **THEN** the remaining products are computed again from all current answers

### Requirement: The finder keeps no answers (REQ-FPF-003)

The product finder SHALL evaluate the answers in the browser and MUST NOT send or store them. It SHALL say "Wij bewaren uw antwoorden niet."

#### Scenario: No request with answers
- **WHEN** a resident answers all five questions
- **THEN** the browser has sent no request containing an answer
