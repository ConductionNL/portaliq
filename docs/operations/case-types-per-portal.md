---
title: Case types per portal
sidebar_label: Case types per portal
description: How an administrator chooses which case types residents see in a portal, and what a case app declares so the choice can name them
---

# Case types per portal

A case app can hold case types residents apply for and case types that stay internal. You choose per portal which ones residents see.

## Hide a case type

1. Open **Portals** and open the portal.
2. Scroll to **Case types** on the portal's page.
3. Switch off **Show in this portal** for the case type.
4. Read the warning: "Residents with a case of this type will no longer see it here."
5. Choose **Save**.

## What residents see

In that portal, a case of a hidden type:

- leaves **My cases**, including cases seen through a mandate;
- answers as not found when its address is opened directly;
- has no request form: the form page opens no form, and the request catalogue leaves the entry out.

Another portal of the same organisation is not affected. It shows the type until you hide it there too.

Nothing is deleted. The case stays in the case app, and messages already sent stay in the inbox. Switch the type on again and the cases come back.

On the **Request forms** page, **Check form** on an entry for a hidden type says "this portal does not show its case type".

## Which case types are listed

- The case types of the portal's published request forms.
- The case types a case app declares, see below.
- Every case type you hid, even when nothing else names it any more.

A case type that only appears on cases, with no form and no declaration, is not listed. Ask the case app to declare its case types.

## For case app authors: declare where your case types live

Add `caseTypeSource` to a collection of kind `cases` in your portal contribution:

```json
{
  "id": "cases",
  "kind": "cases",
  "register": "dossiq",
  "schema": "case",
  "caseTypeField": "caseType",
  "caseTypeSource": { "register": "dossiq", "schema": "caseType", "labelField": "title" }
}
```

- `caseTypeField` is the field on a case that holds its case type id. It defaults to `caseType`. The value may be the id itself or an object with `id` or `uuid`.
- `caseTypeSource` names the register and schema of your case type objects, and the field that holds their name.

The portal hides a case when the id in `caseTypeField` matches a hidden case type.
