---
title: Notices when a case changes
sidebar_label: Notices when a case changes
description: How a resident hears about a change on their case, how a case app asks for it, and what the resident can switch off
---

# Notices when a case changes

A handler moves a case to another status. The resident finds a message about it in their portal inbox. If they want, they also get an e-mail or a push on their phone. The link in each one opens the case.

## What the resident sees

A new message in **Inbox**, for example "Z-2026-104 has been updated", with **Open**. Open goes straight to the case. The message never repeats what changed; the resident reads that on the case itself.

The e-mail says that something changed on their cases and in whose portal, and links to the case. It carries no status, no name and no other value from the case. A resident who is signed out signs in first and then lands on the case. A link forwarded to someone else opens nothing of the case: the portal only shows cases from the reader's own list.

When a handler writes a message to the resident in the case app's own inbox, the resident gets the same short e-mail.

A change the resident makes themselves in the portal is not reported back to them.

## What the resident can switch off

At the top of **Inbox**, **Notification settings** holds one checkbox per kind and channel:

| | E-mail | Push |
|---|---|---|
| Changes on your cases | on | on |
| New messages | on | on |

Everything is on until the resident switches it off. The push column shows only after they allowed push on a device. The message in the inbox is always written: it is the record of what happened. The older e-mail opt-out on the account still switches off e-mail for every kind.

## What a case app declares

A case app asks for these notices in its portal contribution, next to the plain rule keys in `notifications`:

```json
"notifications": [
  "message.created",
  {"ruleKey": "case.updated", "collection": "mijnZaken", "on": {"field": "status", "operator": "changed"}, "titleField": "identifier"}
]
```

- `collection` is one of the app's own collections, scoped by the subject reference on the record. A collection read through `scopeClaim` or `via` cannot carry a rule, because the record does not say whose it is.
- `field` is a field the collection shows to residents. The only operator is `changed`.
- `titleField` names the case in the message. Without it, the collection label is used.

Portaliq drops a rule that breaks one of these and logs a warning naming the app and the rule.

Declare a rule only on a field a resident cares about, such as the status. A field that changes on every save fills the inbox with messages nobody reads.

`message.created` on an app with a `kind: inbox` collection gives each new message in that collection the e-mail nudge.

## What is not reported

- A change OpenRegister saves without the previous version: portaliq cannot see what changed, so it says nothing.
- Changes for staff. These notices are for residents only.
