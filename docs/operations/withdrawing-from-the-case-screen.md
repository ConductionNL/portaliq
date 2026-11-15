---
title: Withdrawing a request from the case screen
sidebar_label: Withdrawing a request
description: What a resident sees when they withdraw their own request in the portal, and what the case type decides
---

# Withdrawing a request from the case screen

A resident who no longer needs a request withdraws it themselves on the portal case screen. The screen shows what the case type allows, asks for a confirmation, and keeps the request readable afterwards.

## What the case type decides

The case type's `portalWithdrawal` block decides everything; the screen only shows the server's answer:

```json
"portalWithdrawal": {
  "openStatuses": ["ontvangen"],
  "closedReason": "Uw aanvraag is al beoordeeld.",
  "targetStatus": "ingetrokken",
  "confirmText": "Als u intrekt, stopt de behandeling."
}
```

- No `portalWithdrawal`: the screen shows nothing about withdrawing.
- The case is in one of the `openStatuses`: the screen shows **Withdraw this request** ("Deze aanvraag intrekken").
- Any other status: the screen shows the `closedReason` and no button.

## What the resident sees

1. **Withdraw this request** opens a confirmation in place. It says what withdrawing means: the case type's `confirmText`, or "If you withdraw, we stop handling your request. You cannot undo this." when the case type gives none.
2. The resident may type a reason. It is optional.
3. **Withdraw request** sends it; **Keep my request** closes the step and sends nothing.
4. The screen says "Your request has been withdrawn." and shows the request again: the answers, read-only, "Withdrawn on" with the date, and the reason when one was given.

Nothing on the screen undoes a withdrawal. A resident who changed their mind contacts the organisation.

When the window closed while the resident had the screen open, the confirmation answers with the server's sentence and the request stays as it was.

## What it sends

`POST /portal/api/citizen/cases/{register}/{schema}/{id}/withdraw` with only `{"reason": "…"}`. The server writes the target status, `withdrawnAt` and `withdrawalReason`, records the write on the case, and raises the withdrawal event for the case app. See [What a citizen may write on their own case](./citizen-writes-on-their-own-case.md) for the route and its refusals.

Try it on a test case of a type with `portalWithdrawal`, then check that the case app picked up the withdrawal event.
