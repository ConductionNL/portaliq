# Design: sign-in-with-an-email-link

## Security note (to review before build)

A mailed link is a bearer credential: whoever reads the mailbox signs in. The design limits what that buys and what it leaks.

| Risk | Measure |
|---|---|
| Account enumeration through the form | the same answer and the same timing for a known and an unknown address |
| A link forwarded or read later | single use, 15 minutes, spent on first open; a second open answers "deze link is al gebruikt" |
| Token theft from storage | only a hash of the token is stored, with the account id and expiry |
| Mail flooding | rate limit per address (3 per hour) and per client address (20 per hour), `#[AnonRateLimit]` |
| A link opened by a mail scanner that follows links | the link opens a page with one button "Inloggen"; the button spends the token, not the GET |
| Session strength | assurance `low`; an action at `substantial` refuses it as it refuses any `low` session |
| Phishing look-alikes | the mail names the portal and the address it was asked for, and contains no other link |

## Why not the generic OIDC provider

The OIDC "e-mail" way needs an identity provider the school does not have. A participant of a training provider has only an address the employer gave. The deviation D-6 (invitation link, then a Nextcloud account) works but gives the participant a password to keep for two course days a year.

## Decision for Ruben

Turn this on for the academy example portal only after the security review signs the table above. Until then the example keeps D-6.
