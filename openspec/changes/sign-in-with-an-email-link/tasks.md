# Tasks: sign-in-with-an-email-link

- [ ] 0. Security review of design.md's table; stop here until it is signed.
- [ ] 1. Mode `email-link` in the portal's `authentication.modes`; the role card on the sign-in page.
- [ ] 2. Token service: hashed single-use token, 15 minutes, spent by the button, not by the GET.
  - PHPUnit for expiry, single use and the hash
- [ ] 3. The request endpoint: same answer and timing for unknown addresses, rate limits per address and client.
- [ ] 4. The mail (portal name, the address, one link); i18n en and nl.
- [ ] 5. learniq declares the mode on the academy portal once reviewed.
