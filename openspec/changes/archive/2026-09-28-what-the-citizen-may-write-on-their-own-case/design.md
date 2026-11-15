# Design: what-the-citizen-may-write-on-their-own-case

## D1. The writable set is read, never kept

`PortalContributionProvider` in dossiq is an eight-field PHP whitelist
today. This change does not move that list into portaliq. The portal asks
the contribution what this audience may see and change, on this case, in
this status, and renders exactly that.

D16 is the reason. The flag is a property of the field, so the field
carries it and every reader asks the field. A portal that keeps its own
list is a second place to be wrong about what a citizen may touch.

## D2. Three acts, not one write endpoint

An amendment, a document and a task answer look alike to a database and
nothing alike to a citizen or to the law.

- An **amendment** changes an answer the citizen already gave. It is only
  open while the case type says it is.
- A **document** is added to the case and is never an edit of an existing
  one.
- A **task answer** was asked for, so it has a recipient, a deadline and a
  place to return to.

Each gets its own act, its own event and its own record on the case.

## D3. The amendment window is a case-type declaration

An aanvraag can be corrected until it is in behandeling, or until the
Awb 4:5 term runs out, or not at all. The case type says which. The portal
shows the form as read-only once the window closes, with the reason, not
with a disabled button and no explanation.

## D4. A citizen write is its own event

`portal.write.client` carries the case, the identity, the act and the
fields. A staff write does not raise it. iTop's `TriggerOnPortalUpdate` is
the same idea and the reason it exists is the same: "de indiener heeft
gereageerd" starts a different rule from "een collega heeft gereageerd".

Without the distinction every rule has to guess from the actor, and the
guess is wrong for a clerk filing on the citizen's behalf.

## D5. The citizen task is the partner task with another audience

`partner-tasks-in-the-portal` already delivers an ask to an outside party
with a deadline and returns the answer to the case. A citizen is another
audience on that mechanism, not a second mechanism. What differs is the
words and the identity kind, both of which are already per audience.

## D6. The status a citizen reads is the one the case app supplied

The contribution carries a public status label. The portal renders it. It
does not translate, shorten or invent one. When the case app supplies no
public label, the portal shows what the contribution gave it, which is
the case app's decision and visible in the case app's own authoring
surface.

The alternative, a portal-side vocabulary, puts the words a citizen reads
in a place the case type author cannot see.

## D7. Every citizen write is attributed and throttled

The record on the case names the portal identity and the mandate it acted
under. The write surface is throttled under ADR-082, per identity and per
case. A portal write surface without a rate limit is a way to fill a
municipality's storage from a phone.

## Risks

- **A field flagged writable that should not be.** The flag is authored in
  the case type, where the author can see the whole set, and the portal
  shows the citizen exactly what is open.
- **An amendment after the decision.** The window is declared, and the
  portal refuses outside it rather than letting the write fail later.
- **A document that is not what it claims.** The file surface the case app
  declares does the scanning and the type checks; this change adds no
  second upload path.
