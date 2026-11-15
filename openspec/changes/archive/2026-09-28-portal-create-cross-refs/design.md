# Design: portal-create-cross-refs

## D-1. The check is the scoped read, not a lookup

`PortalCrossRefGuard` resolves each reference through
`PortalObjectReader::readObject()`, the same call the portal's own detail
route makes. A check that fetched the object directly would answer "it
exists", and existence is not the question a write-IDOR turns on. The scoped
read already fails closed to null on a foreign object, an unresolvable scope
claim and an unreachable OpenRegister alike, so the guard asks one question
and never has to decide what a partial answer means.

## D-2. A malformed guard removes the action

`CrossRefConfigNormaliser::normaliseAction()` returns null for an unreadable
declaration, and `ActionConfigNormaliser` drops that action from the
manifest. This is the opposite of every sibling normaliser and it is
deliberate: those blocks are surfaces, and dropping one closes it; this
block is a guard, and dropping it alone would open the write it guards.

A guard naming a field the action does not whitelist is unreadable too. Such
a guard can never fire, and a guard that can never fire reads as protection
that is not there.

## D-3. An absent reference is allowed unless it is required

`tegenZaakId` on a bezwaar is required; an optional case link on a message is
not. The domain app says which, because only it knows. A value that is
neither a string nor a list of strings refuses: a caller who sends an object
where a uuid belongs is not sending nothing.

## D-4. Anonymous and guarded cannot both be true

An anonymous caller has no subject and therefore no scope, so there is
nothing for a reference to be inside. The two keys are mutually exclusive
and the guard wins: the action keeps its references and loses its anonymous
flag, which leaves it reachable only with a subject.
