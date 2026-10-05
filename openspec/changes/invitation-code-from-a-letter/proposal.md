# Proposal: invitation-code-from-a-letter

## Why

An invitation link reaches a guardian by mail (`invitation-secret-joins-the-signed-in-account`). Not every guardian reads mail, and a school also sends letters home. A letter cannot carry a link anyone would type: the link's secret has 48 characters.

## What changes

- A waiting account can carry a short code instead of a link: twelve characters, shown as `ABCD-EFGH-2345`. The alphabet has no 0, O, 1 or I. Portaliq stores only a keyed hash (HMAC-SHA256 with a key derived from the instance secret), with the same seven-day expiry as a link.
- The five `claim*` fields on `portalAccount` are readable and writable by administrators only. An ordinary signed-in Nextcloud user reads the account without them.
- A code proves a paper letter, not the address. When the join brings the invited address onto an account without one, it arrives unverified. A mailed link proves the address and brings it verified.
- An app asks for it with the same event, `PortalAccountInvitationRequestedEvent`, on the channel `letter`. Portaliq answers the code in the event, because somebody has to print it. Nothing is mailed.
- The signed-in person types the code on "My account", in a new part "Code from a letter". It goes to the same redeem route as a link, with the same checks: a session at substantial or higher, one answer for wrong, expired and used, and the same attempt limits. Capitals, spaces and dashes do not matter.
- A waiting account has one live secret. A new code ends an earlier link, and a new link ends an earlier code.

## Trust

A code is shorter than a link's secret: 60 bits against about 248. The attempt limits are what carry it. Five wrong codes lock the account for an hour, and the count is stored on the account, so a second session or a restart does not reset it. Reaching the route at all takes a sign-in at substantial or higher, so every guess is tied to a real identity. At five guesses an hour against 2^60 codes, guessing is not a practical route.

Offline guessing is the real threat to 60 bits (security review M4). With a plain SHA-256, anybody who could read the stored hashes could test every live code in one pass: with a thousand live codes, about fifteen GPU-hours for a hit, well inside the seven days. So the hash is keyed with a key derived from the instance's own `secret`, and the hash fields are hidden from everybody but administrators. An instance without a secret issues no code. The 48-character link secret (about 248 bits) stays a plain SHA-256.

Unlike a link, a code is seen by staff: the person who prints the letter. That is the price of paper, and it is why the link stays the first choice.

## Limits

- A waiting account still needs an e-mail address to exist (REQ-PIS-001 refuses an account with neither an identity reference nor an address). A school that invites by letter still enters an address, and learniq refuses a letter without one for that reason. Issuing the code itself needs no address. Lifting REQ-PIS-001 is a separate decision.
- The exposure of the fields that existed before this work (`email`, `claims`, `pendingEmailTokenHash`, `activationTokenHash`) to every signed-in Nextcloud user is unchanged here. Narrowing it is a separate change.
- The code has the link's seven days. A letter takes a day or two of that.

## Not changed

- The redeem route and its answers. integriq and what the broker returns. No BSN is read or stored.
