---
title: Acting for one branch
sidebar_label: Acting for one branch
description: How an eHerkenning login for one branch (vestiging) limits the portal to that branch's cases, and what a case app declares for it
---

# Acting for one branch

A company can let an employee sign in with eHerkenning for one branch (vestiging) only. The manager of one shop then sees that shop's cases, not those of the whole chain. The portal header says which branch the session is for.

## Setting it up for an organisation

eHerkenning brokers name the branch claim differently, so the portal reads none until you say which claim holds it. Add `branch` to the organisation's `claimMap` for the eHerkenning provider, with the name of the claim that carries the vestigingsnummer in your broker's assertion:

```json
{
	"issuer": "https://broker.example",
	"clientId": "portal",
	"clientSecret": "...",
	"claimMap": {
		"branch": "urn:etoegang:1.9:ServiceRestriction:Vestigingsnr"
	}
}
```

The portal accepts only a vestigingsnummer of twelve digits. Any other value is ignored, and the person signs in for the whole company, as before.

Signing in through integriq's broker does not carry a branch yet: that needs integriq to put the branch in its envelope.

## What a restricted session sees

A session whose login was for one branch:

- sees the rows of a collection that declares `branchField` only when that field holds its branch;
- sees nothing of a collection that declares no `branchField`, because the login did not grant the whole company;
- gets "not found" for a single case of another branch, the same answer as for a case that is not its own;
- files a new case with the branch filled in, on the field the create action declares as `branchField`.

A refresh keeps the branch. A session cannot widen itself to the whole company.

## What a case app declares

A case app that stores the branch on a business's case names the field on its collection, and on its create action:

```php
[
	'id' => 'mijnZaken',
	'register' => 'dossiq',
	'schema' => 'case',
	'scopeField' => 'portalSubject',
	'kind' => 'cases',
	'fields' => ['title', 'status', 'vestigingsnummer'],
	'branchField' => 'vestigingsnummer',
]
```

The field must be one the collection projects in `fields`, or the portal drops `branchField` and treats the collection as declaring none. Until a case app declares it, a session restricted to a branch sees none of that app's cases. That is the safe answer, not an error.

## Not yet in this version

A person who signed in for the whole company cannot yet narrow to one branch under "Acting for". That needs the list of the company's branches from the KvK, which comes with the registered company details.
