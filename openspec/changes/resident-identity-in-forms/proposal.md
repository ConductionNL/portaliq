---
kind: code
---

# Proposal: resident-identity-in-forms

## Why

Some requests need more than a DigiD login. A street party needs the organiser's signature. A request that is answered by e-mail needs an address that is really the resident's. A joint request needs the partner to sign with their own DigiD. A resident without DigiD may want to share only the attributes a form asks for, with Yivi. And at the desk an employee fills in a form for a resident who cannot do it alone, and the case must say who did.

Open Formulieren 4.0.1 has all five: a signature component (`src/openforms/formio/components/vanilla.py:997`), e-mail verification with a one-time code (`src/openforms/submissions/models/email_verification.py:35`), co-signing after an invitation (`src/openforms/formio/components/custom.py:1373`, `sdk:src/routes/cosign.tsx`), Yivi login with attribute prefill (`src/openforms/authentication/contrib/yivi_oidc/plugin.py:66`, `src/openforms/prefill/contrib/yivi/plugin.py:30`) and an employee filling in for someone, recorded on the submission (`src/openforms/authentication/models.py:413` RegistratorInfo). Portaliq has none of them: the signature in `PortalIntakeController.php:163` is the instance's nonce signature, `src/formFields/EmailField.vue` is a plain field, and no submission records who filled it in.

Four Zuiddrecht boards draw them: **FormulierKaart** (the signature box), **FormulierVelden** (the e-mail code), **MedeOndertekenen** with **WooVerzoekVerstuurd** (the invitation, the co-signer's check, and "Wacht op mede-ondertekening") and **FormulierInloggen** with **PtFormulierInstellingen** ("Voor medewerkers", "Medewerker vult in voor een inwoner"). Yivi has no board yet.

## What changes

- **A signature field.** The resident draws a signature with mouse, finger or pen, or types their name instead. It travels as an image with the submission.
- **E-mail verification.** An e-mail field can require a six-digit code sent to the address before the form can be sent.
- **Co-signing.** A form can require a second person to co-sign. After submission the co-signer gets a mail with a code, signs in with their own DigiD, reads the request and co-signs or refuses. Delivery waits for the answer.
- **Yivi.** A portal can offer Yivi as a sign-in route through its broker, and a form can ask for a set of Yivi attributes that prefill its fields.
- **An employee fills in for a resident.** A form can allow staff to fill it in with their work account, for a resident (by BSN), a company (by KvK number) or under their own name with the applicant noted. The submission and the case record both the applicant and the employee.

## Rows covered

- `int-signature-field`, screen FormulierKaart.
- `int-email-verification`, screen FormulierVelden.
- `int-cosign`, screens MedeOndertekenen, WooVerzoekVerstuurd, PtFormulierInstellingen.
- `sig-yivi`, no board yet (on the missing-boards list).
- `id-staff-fills-on-behalf`, screens FormulierInloggen, PtFormulierInstellingen.

All from decision 104.

## Who owns what

- The signature field type is authored in buildiq (`buildiq/forms-signature-audio-and-location`, row `form-signature`). This change renders and stores it.
- Yivi reaches portaliq through the OIDC broker (integriq), like DigiD and eHerkenning do (`portal-broker-envelope-login`). Portaliq adds the route kind and the attribute mapping, not a Yivi server.
- BRP and KvK lookups for the employee use OpenRegister's providers (`BrpPersonProvider`), as `PortalApplicantPrefill` does.

## Out of scope

- Signing a prepared document (`dem-tnd-sign-in-portal`, `2026-09-28-case-actions-sign-a-document`). The MedeOndertekenen board is shared with that row; this change uses only its co-sign half.
- Qualified electronic signatures.
