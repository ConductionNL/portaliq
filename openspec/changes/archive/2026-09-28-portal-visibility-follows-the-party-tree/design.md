# Design: portal-visibility-follows-the-party-tree

## D1. The tree is read, never copied

openregister owns parties and the relations between them.
`portal-identity-and-the-organisations-cases` already refused to copy that
into the portal, for the right reason: two copies means two places to be
wrong about who may see a case.

Nothing here changes that. The portal resolves a scope by walking the
relations openregister holds, at the moment it is asked, and stores no
hierarchy of its own.

## D2. Reaching down is a property of the mandate

A mandate gains one thing: whether it covers the organisation named, or
that organisation and the entities below it.

The default is the organisation named. A group that wants a holding to see
its subsidiaries says so once, on one mandate, instead of recording eleven.
A group that does not want it changes nothing and loses nothing.

This keeps eHerkenning ketenmachtiging intact. A ketenmachtiging is flat,
it is what REQ-PIOC-002 already serves, and it stays the default shape.

## D3. The case app can refuse the tree for its own case type

Not every case about a subsidiary is the holding's business. A case about a
subsidiary's staff, a report under the Wbk, a dispute between the parent
and the subsidiary: all three are cases the parent must not read through a
group relation.

So the case type declares whether its cases may be reached through a
parent. The portal asks, and a case type that says nothing is not
reachable through the tree. Fail closed, per ADR-046.

## D4. The walk is bounded, because a group is not a fixed size

A holding with eleven subsidiaries is small. A group with four hundred
entities and six levels exists, and an unbounded read of party relations
is the footgun ADR-058 was written about.

The walk carries a maximum depth and a page size. Past the bound the portal
refuses with an explanation rather than returning a truncated list that
looks complete. A truncated case list is worse than a refusal, because
nobody can see what is missing.

## D5. Every row says which entity and which mandate

"Waarom zie ik dit" is the first question a group asks and the first thing
an auditor asks after. REQ-PIOC-002 already answers it for a flat mandate.
A case reached through the tree answers it with two names: the subsidiary
the case belongs to, and the mandate on the parent that reaches it.

## D6. The group changes, and the view follows without a grant being touched

The whole point of the row is that nobody maintains eleven grants. A
subsidiary sold stops being visible at the next read. A subsidiary acquired
becomes visible at the next read.

That also means the relation is the control. Selling an entity in the party
register is what revokes the access, and the case list is derived, never
cached past the request.

## D7. Visibility is not ownership

A parent reading a subsidiary's case is a read. The case stays filed
against the subsidiary, the requester claim does not move, and a write made
through a parent mandate records that mandate, exactly as REQ-PIOC-008
requires of any mandate.
