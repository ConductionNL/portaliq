---
title: Signature and verified e-mail in forms
sidebar_label: Signature and verified e-mail
---

# Signature and verified e-mail in forms

Two field kinds a form can use to know more about the resident who fills it in.

## A signature

Give a field `type: signature`. The resident draws in a box with a mouse, a finger or a pen, and can press **Opnieuw tekenen** to start over. Dragging is not possible for everyone, so a link under the box, **Typ uw naam in plaats van te tekenen**, swaps the box for a text input. The typed name is drawn as the signature image in a plain font.

- The answer is a PNG data address. The server accepts only a PNG of at most 200 kB and refuses anything else.
- A required signature that is empty is refused with the field named.
- The image is part of the submission's answers, so the case app receives it with the other answers. It is not a separate file in OpenRegister.
- The review step says "Handtekening geplaatst" and never prints the image.

## A verified e-mail address

Give an `email` field `verify: true`. Next to the address the resident sees **Stuur een code**. The portal mails a six-digit code to the address:

| Rule | Value |
|---|---|
| The code works for | 15 minutes |
| Wrong tries before the code is void | 5 |
| A new code can be asked after | 60 seconds |
| Codes one address may be sent | 5 an hour |
| Codes one client may ask for | 20 an hour |

The code is kept only as a hash, in the distributed cache. Without a distributed cache the routes answer 503: a code the server cannot remember is never sent.

A right code answers a signed proof, tied to the portal, the form and the address, valid for two hours. The form sends the proof along with its answers. The server checks it again on submit and refuses an address without a proof, or with the proof of another address or form. The submission records the verified addresses and the time in `verifiedEmails`.

Changing the address after it was checked drops the proof.

The mail is the template `form-email-code`, which a portal can reword under **Mail templates**. The variables are `portal` and `code`.

## Routes

| Route | What it does |
|---|---|
| `POST /portal/api/intake/email-code` | Send a code. Answers 404 for a form with no verified e-mail field. |
| `POST /portal/api/intake/email-code/check` | Check a code and answer the proof. |

## Not built yet

Co-signing, Yivi and an employee filling in for a resident are in the same change and are not built. Their fields exist on the binding and the submission so a later change can use them.
