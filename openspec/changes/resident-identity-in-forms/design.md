# Design: resident-identity-in-forms

## Screens

Canvas `5NkFW28vZUUij43xzxHg5a`.

### Signature (FormulierKaart)

| Board element | Here |
|---|---|
| "Uw handtekening", hint "Teken met uw muis, vinger of pen in het vak." | label and description of a `signature` field |
| The drawing box, "Opnieuw tekenen" | a canvas with a clear button |

Drawing is a dragging movement, so WCAG 2.2 SC 2.5.7 asks for a single-pointer alternative. Under the box sits a link "Typ uw naam in plaats van te tekenen", which swaps the box for a text input; the typed name is rendered as the signature image in a plain font. This is an addition to the board.

### E-mail code (FormulierVelden)

| Board element | Here |
|---|---|
| "E-mailadres", hint "Wij controleren eerst of dit adres van u is." | an `email` field with `verify: true` |
| "Wij hebben een code gestuurd naar sanne.devries@example.nl. Vul de code hieronder in. De code is 15 minuten geldig." | shown after the code is sent |
| "Code uit de e-mail", "Code controleren" | six-digit input and its button |
| "Geen code gekregen? Stuur een nieuwe code" | resend, available after 60 seconds |

### Co-signing (MedeOndertekenen, WooVerzoekVerstuurd, PtFormulierInstellingen)

| Board element | Here |
|---|---|
| Mail "Wilt u een aanvraag mede-ondertekenen?", code "7RQ-K2M", "Doe dit voor 22 oktober 2026." | template `form-cosign-invite`; a signed link (`#cosign=<token>`) instead of a typed code; deadline from `cosign.deadlineDays` |
| Start page "Aanvraag mede-ondertekenen", "Uw code", "Inloggen met DigiD en controleren" | the co-sign page the link opens on the portal; signed out it offers the sign-in, the link is kept over it |
| "Controleer en onderteken mee", the request summary, "U kunt de aanvraag niet wijzigen.", checkbox "Ik heb de aanvraag gelezen en onderteken mee.", "Mede-ondertekenen", "Weigeren" | the review page after DigiD |
| On WooVerzoekVerstuurd: "Wacht op mede-ondertekening door Henk de Vries" and the deadline | a status line on the confirmation and on the case in Mijn zaken |
| Tab "Mede-ondertekenen" ("Uit: geen tweede ondertekenaar nodig") | `cosign` on the binding |

### Employee fills in (FormulierInloggen, PtFormulierInstellingen)

| Board element | Here |
|---|---|
| Section "Voor medewerkers", "Verder als": Inwoner (BSN), Bedrijf (KvK-nummer), "Uzelf, met de inwoner als aanvrager"; "BSN van de inwoner"; "Inloggen met werkaccount" | shown when the binding has `staffMayFill: true` |
| Setting "Medewerker vult in voor een inwoner. De inwoner staat als aanvrager op de zaak" | `staffMayFill` |

### Yivi

No board. Until one exists, Yivi is one more button on the sign-in choice ("Inloggen met Yivi", "Deel alleen de gegevens die dit formulier nodig heeft."), and before the Yivi app opens the form lists the attributes it asks for.

## Data

- `portalFormBinding` gains `cosign: { required, deadlineDays, nameField, emailField, kind, title, endpoint }`, `staffMayFill` (boolean), `yiviAttributes` (list of attribute ids with the field each prefills).
- `portalIntakeSubmission` gains `filledBy: { uid, displayName }`, `applicant: { kind: bsn|kvk|named, value }`, `cosign: { state: awaiting|signed|refused|expired, email, signedAt, cosignerSubject }`, `verifiedEmails` (address and time).
- A field of type `signature` stores its image as a PNG file on the submission object (OpenRegister file), at most 200 kB, refused when empty.

## Server

- E-mail code: `POST /api/intake/{route}/email-code` sends a code, `POST /api/intake/{route}/email-code/check` checks it. A code lives 15 minutes, allows 5 tries, and a new one can be asked after 60 seconds. A verified address is held for the draft or the session; the submit path refuses a `verify` field whose address was not verified. Throttled per address and per client.
- Co-sign (decision 271): on submit with `cosign.required`, the case is created at once with the server value `intakeObligations: [{kind, title, holderName, holderEmail, dueAt, state: awaiting}]`; the case app places the wait (dossiq `obligations-placed-at-intake`). After the create the portal mails the co-signer a link with a token signed by the instance secret over the portal, route, case, reference, kind, due date and a hash of the submitter's subject; nothing is stored in the portal. `GET /portal/api/cosign` shows the request to the signed-in co-signer, `POST /portal/api/cosign` forwards `{case, kind, answer}` to `cosign.endpoint` with the signed assertion. The co-signer's subject MUST differ from the submitter's (checked by the portal against the hash, and by the case app against the case). The case app records the subject and level of assurance; a refusal or a passed due date follows the case app's rule for the kind.
- Yivi: a broker route kind `yivi` in the organisation's login routes (REQ-BEL-001). The envelope carries only the disclosed attributes. The session's level of assurance is what the broker states, and a form whose `minTrust` is higher does not open.
- Staff: a Nextcloud user in the portal's desk group signs in through the existing Nextcloud way in (`portal-nextcloud-account-login`). Choosing Inwoner prefills from BRP for the entered BSN, Bedrijf from KvK. Every lookup writes an audit line with the employee and the BSN or KvK number. The delivery names the applicant as applicant and the employee as `filledBy`.
