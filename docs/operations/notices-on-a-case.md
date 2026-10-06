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

Everything is on until the resident switches it off. The push column shows only after they allowed push on a device, and only while the portal has a push transport that really delivers. The message in the inbox is always written: it is the record of what happened. The older e-mail opt-out on the account still switches off e-mail for every kind.

## What a case app declares

A case app asks for these notices in its portal contribution, next to the plain rule keys in `notifications`:

```json
"notifications": [
  "message.created",
  {"ruleKey": "case.updated", "collection": "mijnZaken", "on": {"field": "status", "operator": "changed"}, "titleField": "identifier"}
]
```

- `collection` is one of the app's own collections, scoped by the subject reference on the record. A collection read through `scopeClaim` or `via` can only carry a rule that names its recipients, see below, because the record itself does not say whose it is.
- `field` is a field the collection shows to residents. The only operator is `changed`.
- `titleField` names the case in the message. Without it, the collection label is used.

### Reaching residents by a claim

Some records hold the app's own reference of the resident, not their portal reference. A school's conference booking holds the guardian's reference in the school app, which the guardian's portal account carries as the claim `claims.learniq.guardianRef`. Such a rule names its recipients:

```json
{"ruleKey": "conference.answered", "collection": "parentConferenceSignups",
 "on": {"field": "lifecycle", "operator": "changed"},
 "recipients": {"field": "guardianRef", "claim": "guardianRef"},
 "messages": {
   "acknowledged": {"subject": {"nl": "Gesprekstijd bevestigd"}, "body": {"nl": "De leerkracht heeft uw gesprekstijd bevestigd: {startsAt|datetime}, met {teacherName}."}}
 }}
```

- `recipients.field` is the record field that holds the claim value. `recipients.claim` is one of the app's own claims, as a bare name or as `<app>.<name>`. An app cannot address residents by another app's claim.
- Portaliq finds the active portal accounts whose claim holds that value, then reads the record as each of them through the collection, with its `scopeClaim`, `via` and `filter`. Only an account that may read the record gets the message. A guardian of another family never does, even with a matching claim.
- A record without a value in `recipients.field` reaches nobody.

### The words of the message

`messages` gives the subject and body per new value of the field. A value without an entry is not reported, so a parent who cancels their own booking hears nothing. Each text is a string or a map of language codes; the portal's language is used, else English, else the first one given.

A placeholder `{field}` prints a field of the record, `{field|datetime}` prints a date and time as `13-10-2026 18:00`. A placeholder may only name a field the collection shows to residents. Without `messages`, the message reads "... has been updated".

Portaliq drops a rule that breaks one of these and logs a warning naming the app and the rule.

Declare a rule only on a field a resident cares about, such as the status. A field that changes on every save fills the inbox with messages nobody reads.

`message.created` on an app with a `kind: inbox` collection gives each new message in that collection the e-mail nudge.

### Which plain keys work

A plain key in `notifications` is kept only when something sends it:

- `message.created` and `status.changed`, which portaliq sends itself.
- The `ruleKey` of a change rule in the same list.
- A key in the app's own namespace, `<app>.<key>`, such as `dossiq.invoiceDue`. The app sends it by writing a portal message with that `ruleKey`; the part before the first dot names the app whose declaration counts.

Any other key, such as a bare `tenderPublished` or another app's `pipelinq.question.answered`, could never send anything. Portaliq drops it and logs a warning naming the app and the key, with the form to use instead.

## What is not reported

- A change OpenRegister saves without the previous version: portaliq cannot see what changed, so it says nothing.
- Changes for staff. These notices are for residents only.
