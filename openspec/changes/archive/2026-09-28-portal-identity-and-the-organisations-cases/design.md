# Design: portal-identity-and-the-organisations-cases

## D1. Two identity kinds, chosen by the case type

`identityKind` on the case type's portal declaration: `account`,
`reference`, or both. `reference` is a case number and a verified e-mail,
with a one-time link and no account. `account` is a session behind DigiD,
eHerkenning or eIDAS.

D8 is answered as option 3, and the order matters: `reference` first. It
is small, it unblocks eight candidates, and it is what a melding openbare
ruimte needs. `account` follows through `portal-identity-space`, which
already provisions the record.

A case type offering both lets the citizen choose at the door. A case type
offering only `account` never shows the reference route, which is what a
vergunning needs.

## D2. The organisation view is a scope, not a second tree

A `portalAccount` already carries `organisation`. The cases of an
organisation are the cases whose requester claim resolves into that
organisation, filtered by the mandate that grants it. The mandate is
named on the view, because "why can I see this" is the first question a
company asks and the first thing an auditor asks after.

The alternative, copying the party tree into the portal, means two places
to be wrong about who may see a case. openregister owns parties. The
portal reads them.

## D3. The mandate decides, and it can be narrower than the organisation

eHerkenning ketenmachtiging is not "everyone at this KvK number". A
mandate can cover one case type, one period, or one case. The scope is
built from the mandates the identity holds, so an employee with a mandate
for `bouwvergunning` sees those and no others. The default for an
organisation with no mandate recorded is nothing, not everything.

## D4. An account issued at the desk, and an invitation with a state

Both are `portalAccount` provisioning, which `portal-identity-space`
already specifies from an identity reference or a verified contact. This
change adds the surfaces: a desk action for a clerk, and an invitation to
an e-mail address whose state (sent, opened, accepted, expired) is
visible to the person who sent it.

Invitations that vanish into a mailbox are the reason a clerk falls back
to a token.

## D5. Registration is a policy with three settings, not a switch

`registration`: `off`, `approval`, `activation`. Plus `allowedDomains[]`,
empty meaning any. A gemeente that wants `@gemeente.nl` and nothing else
sets the list; the alternative is an invite process somebody forgets to
close, which is the lane's own clause.

## D6. The challenge is ours, and it runs on our hardware

Proof of work, with a honeypot as the lighter option. No hosted captcha,
because sending a citizen's browser to a third party to prove they are
human is a data transfer a municipality has to justify. GLPI and osticket
both refused the vendor and shipped their own, and that is the pattern we
follow.

The challenge sits in front of the public form and in front of
self-registration. It is one mechanism, configured per surface.

## D7. Deletion is carried out, not requested by mail

A citizen asks for their portal account to go. The product records the
request, removes the account and its claims, and keeps what the law
requires it to keep: the cases stay, the link to the person is cut. AVG
article 17 is about the account, not about the dossier, and saying which
is which is half of answering the request honestly.

## D8. Switching organisation is a session act, not a second login

A person acting under more than one mandate picks which one they act
under, inside the session, the way GLPI switches entity and profile. Every
write records the mandate it was made under. Functiescheiding is the
reason: the same person can be applicant for one case and mandated
representative for another.

## Risks

- **An organisation scope that is too wide.** The default with no mandate
  is nothing. A mandate is required before a case appears, and the view
  names it.
- **Proof of work on an old phone.** The work factor is configurable and
  the honeypot is available for a surface that cannot afford it.
- **A pending account that is never claimed.** Pending accounts expire,
  and an expired invitation says so to the person who sent it.
- **Deletion that takes the case with it.** The cases are not the
  account. The tasks name the separation explicitly and the tests assert
  the case survives.
