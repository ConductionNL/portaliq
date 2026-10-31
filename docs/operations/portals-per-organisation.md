---
title: Portals for several organisations
sidebar_label: Portals per organisation
---

# Portals for several organisations

One installation can run portals for several organisations. Each organisation has its own portal, host and data. This page is for a hosting party.

## What is kept apart

Every schema of the register says how it is kept apart, in `SchemaTenancy::MAP`:

- **organisation**: a resident's own records (accounts, sessions, messages, submissions, cases, mandates). A row without an organisation belongs to no tenant and is never returned, and a session without an organisation reads nothing of these schemas.
- **portal**: content and operation of one portal (menus, pages, news, forms, traffic, notices).
- **subject**: a record that belongs to one resident by their reference.
- **parent**: a record that hangs on another, for example the messages of a report.
- **global**: shared on purpose, with a stated reason. The ones still waiting for a portal field are listed in the class and pinned by `SchemaTenancyCensusTest`.

A test fails when a schema is added without a declaration, so a new schema cannot ship without this decision.

## Add an organisation's portal

```
occ portaliq:portal:provision <organisation> <slug> <title> <host>
```

For example `occ portaliq:portal:provision org-b zeist "Gemeente Zeist" mijn.zeist.example`. It creates:

- the portal, as a draft, with authentication set to `public` and the host unverified,
- a menu,
- a home page.

It refuses a slug that another portal holds, and a host that any portal lists, whichever organisation holds it. Nothing is written when it refuses. It prints the DNS TXT record to publish, with the name `_portaliq-verify.<host>`.

The portal goes live only when the host verifies and an administrator sets the portal to published.

## What is not covered

Running the hosting is yours: contracts, infrastructure, backups and billing. Portaliq does not give each organisation its own database; that is a question for OpenRegister. Before you rely on the stricter organisation check on an existing instance, count the rows of the organisation-scoped schemas that have an empty organisation, and stamp them from their portal.
