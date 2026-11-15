# Proposal: sign-in-with-an-email-link

## Why

The academy's sign-in board ([warmtepompacademie/Inloggen](https://identity.conduction.nl/screens/board?id=warmtepompacademie/Inloggen), 8 October 2026) offers a participant "Als deelnemer: Voor uw cursusdagen en certificaten. U heeft geen wachtwoord nodig: de link werkt 15 minuten. Het e-mailadres waarmee u bent ingeschreven. Stuur mij een inloglink", next to eHerkenning for the employer. The analysis ([warmtepompacademie/Nodig](https://identity.conduction.nl/screens/board?id=warmtepompacademie/Nodig)) marks it "Nog uitzoeken". Plan gap G-19; deviation D-6 covers the short term (the claim lane's invitation link creates the participant's account, then the `nextcloud` mode). Portaliq's "e-mail" way in is the generic OIDC provider (`PortalWaysInResolver`). Lane T2 asks for this proposal with a security note.

## What Changes

- A new sign-in mode `email-link` a portal may declare. A visitor enters an address; if it belongs to a portal account of this portal with that mode allowed, portaliq mails a one-time link valid 15 minutes. Opening it signs the person in at assurance `low` and spends the link.
- The answer to the form is the same whether or not the address is known ("Als dit adres bij ons bekend is, ontvangt u een link"), so the form does not reveal who has an account.
- Rate limits per address and per client, and a short-lived single-use token stored only as a hash.
- The security review of 8 October (design.md) narrows it: only accounts of identity type `email` with no broker identity, a sign-in address an `email-link` session cannot change, a queued job for every request, a conditional spend, a browser cookie with a typed address in another browser, and no fragment in logs or traffic.
- The mode is off unless a portal declares it; the sign-in page shows it as a role card with its label and hint (`authentication.modeLabels`).

## Not in this change

- Creating an account from the link: only an existing portal account (created by an invitation or by the app) can sign in.
- Raising assurance: an `email-link` session is `low`; actions that need more refuse it as today.
