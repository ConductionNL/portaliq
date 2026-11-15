---
title: My registered details
sidebar_label: My registered details
description: How residents and business users see what the BRP or the KvK holds about them, and how they ask for a correction
---

# My registered details

A signed-in resident opens **My details** in the portal and sees what the Personal Records Database (BRP) holds about them: name, date of birth and address. A business user who signed in with eHerkenning sees what the Chamber of Commerce (KvK) holds about their company: trade name, KvK number, legal form and every branch.

The portal reads the record when the section opens. It stores nothing and never sends the citizen service number (BSN) to the browser.

## What you configure

The portal reads both registrations through OpenRegister. It holds no address, token or certificate of its own. Configure two OpenConnector sources:

| Source | Used for | OpenRegister provider |
| --- | --- | --- |
| `brp-haalcentraal` | the resident's record (Haal Centraal BRP Personen) | `BrpPersonProvider` |
| `kvk` | the company's record (KvK Zoeken) | `KvkProvider` |

Without a source the section says "Your registered details cannot be shown right now." The server log names the provider and the cause, never the BSN or the KvK number.

## Who sees a record

The portal looks up the identity on the signed-in account:

- a DigiD or eIDAS account whose identity reference is a valid BSN gets the BRP record;
- an eHerkenning account whose identity reference is an 8-digit KvK number gets the KvK record;
- anyone else reads "The portal cannot show registered details for this way of signing in."

Whether the account holds a BSN depends on your sign-in broker. A broker that sends a pseudonym leaves the section empty for every resident, and the section says why.

## Letting residents ask for a correction

The portal changes no registration. A correction is a request to your organisation, sent through an ordinary request form.

1. Publish the correction form as a form binding on the portal (**Request forms**).
2. On the portal record, set `registeredDetails.correctionFormBinding` to that binding's id.
3. For an address investigation, publish a second binding and set `registeredDetails.addressInvestigationFormBinding`.

```json
{
	"registeredDetails": {
		"correctionFormBinding": "5b0f6c3e-0000-4000-8000-000000000001",
		"addressInvestigationFormBinding": "5b0f6c3e-0000-4000-8000-000000000002"
	}
}
```

Residents then see "Report an error in these details" under their record, and "Something wrong at this address?" under the address. A business user sees only the correction link. An unset or unpublished binding shows no link.

## The number of residents at an address

The section shows how many people are registered at the resident's address once OpenRegister can count them without names. Until then it says "The number of residents at this address is not available."
