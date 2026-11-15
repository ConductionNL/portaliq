# Proposal: event-sign-up-by-a-pupil-with-seats

## Why

[vaartveld/Artikel](https://identity.conduction.nl/screens/board?id=vaartveld/Artikel) (8 October 2026) is a news item about an evening: "Informatieavond profielkeuze op dinsdag 3 november", facts Wanneer, Waar, Voor wie ("Leerlingen van 3 havo en 3 vwo en hun ouders"), Aanmelden ("Tot en met vrijdag 30 oktober"), and a card "Kom je ook? Meld je aan met je ouders, dan weten wij hoeveel stoelen er nodig zijn." with "Aanmelden voor de avond" and "Je logt eerst in bij Mijn Vaartveld."

Portaliq's `event` has guardian RSVP for one of their own children and sign-up roles with a capacity (`portaliq-cms`, "An event is authored per school, group or child, with guardian RSVP"). A pupil cannot answer for herself, an answer carries no number of seats, and a news item cannot carry the event's sign-up. Lane T's gap list names it.

## What Changes

- An `event` MAY set `rsvpBy: [guardian, learner]`; a signed-in pupil in the event's audience may then answer for herself. One answer per child per event, whoever gave it; the last answer counts.
- An answer MAY carry `seats` (1 to the event's `maxSeatsPerAnswer`, default 4) when the event sets `askSeats`; the event shows the seats taken and, with a `capacity`, refuses an answer that would pass it.
- A `newsItem` MAY name an `eventRef`; the article then shows the event's facts and a sign-up card. A visitor who is not signed in sees "Je logt eerst in bij Mijn {portal}" and the sign-in button returns to the article.
- The `signupDeadline` closes the answers in words ("Aanmelden kon tot en met vrijdag 30 oktober").

## Not in this change

- Seats per role (`signupRoles`): the volunteer roles keep their own capacity.
