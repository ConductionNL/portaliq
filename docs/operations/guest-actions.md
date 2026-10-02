---
title: Guest actions from a signed link
sidebar_label: Guest actions
---

# Guest actions from a signed link

A customer gets an invoice by mail and wants to pay it. A booker gets a confirmation and wants to withdraw. Neither has a portal account, and neither should need one for a single act. A guest action lets your app put that one act behind a link it signs itself.

Portaliq shows the page, asks the person to confirm and forwards the act to your app with the token. Your app decides whether the token is good. Portaliq never decides that for you.

## Declare the action

A guest action is an endpoint action in your portal contribution with four extra keys:

```json
{
  "id": "pay",
  "type": "endpoint",
  "endpoint": "/apps/shillinq/api/portal/pay",
  "guest": true,
  "tokenField": "payToken",
  "previewEndpoint": "/apps/shillinq/api/portal/pay/preview",
  "label": "Pay now",
  "confirmText": "You will go to the payment page.",
  "fields": []
}
```

- `guest: true` opens the guest route for this action. Any other value is removed.
- `tokenField` names the body field your app reads the token from. Portaliq writes the token there over anything the browser sends.
- `previewEndpoint` (optional) answers what the link is for before the person acts. Without it the page shows a plain button.
- `minTrust` may not be above `low`. A guest action that asks for more is dropped whole, as is one whose endpoints are not on this instance.

## Send the link

Mail the person a link of this form:

```
https://<your-instance>/apps/portaliq/site?portal=<portal>#guest/<app>/<action>/<token>
```

The token sits in the fragment, so it never reaches a server log. The site reads it once and removes it from the address bar.

## Answer the preview and the act

Portaliq posts `{ "<tokenField>": "<token>" }` to `previewEndpoint`, with a guest assertion: audience `guest`, trust `low`, and a subject that is a hash of the token.

- Answer `{ "summary": "Invoice 2026-0412, 120 euro" }` to show what the link is for.
- Answer `{ "available": false, "reason": "Workshops are exempt from withdrawal." }` when the act is no longer possible. The page shows your reason and no button.

After the person confirms, Portaliq posts the declared fields and the token to `endpoint`.

- Answer `{ "redirectUrl": "https://..." }` to send the person to a checkout. Portaliq follows only `https` addresses.
- Answer `{ "message": "..." }` to show a sentence. Without one the page shows your `successText`, or "Done. You can close this page."
- Answer an error status with `{ "message": "..." }` to refuse. Without a message the page says "This link cannot be used."

An unknown app, an unknown action and an action without a preview all answer the same 404, so a probe learns nothing. Portaliq records each forward with a hash of the token, never the token.

## Next

Add the declaration to your contribution, then mail yourself a link and open it on a test portal.
