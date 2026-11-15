# Design: withdrawing-your-own-case-from-the-portal

## D1. Withdrawal is a declaration, not a portal feature

The portal holds no list of case types a citizen may drop. It asks the
contribution, per case, in its current status, and renders the answer. A
case type that says nothing offers nothing.

The alternative is a portal setting, and a portal setting is a second
place to be wrong about a statutory decision. An aanvraag that has been
decided cannot be withdrawn, and only the case app knows it has been
decided.

## D2. The window is the case type's, and the reason is shown when it closes

Three shapes cover what municipalities and employers actually do: until a
named status, until a term expires, or never. The case type picks one.

A closed window renders as text, not as a button that fails. The citizen
who cannot withdraw any more is told why, which is the same rule
`what-the-citizen-may-write-on-their-own-case` applies to a closed field.

## D3. The target status is fixed by the server

`portal-status-transitions` already solved this shape for the supplier
portal: a row action carries a `set` map the server applies over the
client body, and the client sends no status. Withdrawal is that action
with an audience of `client` and a confirmation in front.

A citizen who PATCHes `{status: "toegekend"}` at the withdraw action still
lands on the status the case type named.

## D4. A confirmation, with an optional reason

Withdrawal ends a request. It gets a confirmation step naming what will
happen, in the case app's own words where it supplies them.

The reason is optional and free text. It is recorded on the case and
travels with the event, because "waarom heeft de indiener ingetrokken" is
the first thing a handler asks and the first thing a jaarverslag counts.

## D5. Its own event, not a status change somebody has to interpret

`portal.withdraw.client` carries the case, the identity, the mandate it
was made under and the reason. A rule stopping an Awb clock, closing a
payment request or cancelling an inspection binds to that, not to a
status string it has to recognise.

This follows ADR-041 and the event `what-the-citizen-may-write-on-their-own-case`
introduced for a citizen write. A withdrawal is not a write on a field, so
it does not ride the same event.

## D6. Authorization is an action check, not a read check

Seeing a case and ending it are different rights. ADR-023 splits them, and
the portal asks for the withdraw action by name. An identity acting under
a mandate records the mandate on the withdrawal, so the company can see
who dropped the application.

## D7. What a withdrawal is not

It is not a delete, not an edit of the original request, and not
reversible from the portal. The request stays readable after it, with the
withdrawal beside it. Reopening is a handler's decision, in the case app.
