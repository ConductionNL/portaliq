# Design: a-report-without-an-account-and-a-custodian-who-may-reveal-it

## D1. A third way in, because the second one asks for too much

`portal-identity-and-the-organisations-cases` has two identity kinds.
`account` is a session behind DigiD or eHerkenning. `reference` is a case
number plus a verified e-mail address.

A reporter under the Wet bescherming klokkenluiders (Wbk) may refuse both,
and the law lets them. So the report surface takes a third kind,
`receipt`: a code the portal issues, and nothing else. No address to
verify, no message to send, nothing to correlate against a mailbox.

The code is the whole credential, which is why the rest of this design is
about protecting it.

## D2. The code is shown once and cannot be re-sent

There is nowhere to re-send it to. The portal says so before it issues the
code, and again on the screen that shows it.

A reporter who loses the code files a new report. That is the honest
answer, and pretending otherwise would mean holding a recovery address,
which is the identifier the reporter declined to give.

## D3. The code is a credential, so it is signed and throttled

A code that opens a thread is a bearer credential whose only protection is
that it is hard to guess. ADR-054 and ADR-082 both name that shape. The
code is long, generated from a cryptographic source, stored hashed, and
every attempt to open a thread with a wrong one is registered with the
throttler.

The reveal surface is not reachable from the portal at all, only from the
instance, which keeps the guessing surface to the thread.

## D4. The identity lives apart from the report

Where a reporter gives contact details, the report body and the identity
are two records with a reference between them, not two fields on one
object. A handler reading a report reads the body. Nothing joins the
identity in for a list, a search, an export or a notification.

One record means one careless view leaks everything. Two records means a
reveal is a deliberate act with a name on it, which is what the row asks
for.

## D5. The reveal is a request somebody answers, not a permission somebody holds

A motivated request names the report and says why. The custodian the
declaration names allows or refuses it. A reveal that is allowed writes a
record: who asked, why, who allowed, when, and what was revealed.

The custodian is a role in the declaration, not a Nextcloud admin. An
instance administrator who is not the custodian cannot reveal, and the
record makes that checkable afterwards.

## D6. The thread is the feedback obligation, made visible

The Wbk sets seven days for the acknowledgement and three months for the
feedback. Both are declarations the case app supplies, so the portal
renders a term rather than owning one. When the law moves, one declaration
moves.

The reporter opens the thread with their code and sees where the term
stands. That is also the only place a reporter can be told anything,
because there is no inbox and no address.

## D7. The surface records nothing that would identify the reporter

No visitor analytics on the reporting pages, no third-party challenge, no
client address against the report. `portal-traffic-analytics` must exclude
the surface by declaration, not by an administrator remembering to switch
it off.

The challenge in front of the form is the proof of work
`portal-identity-and-the-organisations-cases` specifies, which runs here
and sends nobody anywhere.
