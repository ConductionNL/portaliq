---
title: Access requests
sidebar_label: Access requests
description: How a portal user asks for access to someone else's cases, and how your organisation answers
---

# Access requests

A bookkeeper keeps the books for a company, but the portal does not show that company's cases to them. They ask for access in the portal. Someone in your organisation says yes or no in Nextcloud. A yes opens the cases.

## What the portal user does

Signed in to the portal, they open **Access to cases**. They fill in whose cases they need, for example a KvK number, and why. A request without a reason is not sent.

Below the form they see every request they made:

- **Waiting for an answer** until someone decides.
- **Granted** once someone said yes.
- **Refused**, with the reason your colleague gave.

## What you do

Open **Access requests** in Portaliq. The list shows the pending requests of your organisation, with who asked, for whom, and why.

- **Grant** asks you to confirm. Portaliq then records a mandate for the asker on behalf of that party, and their cases open for the asker. If the mandate cannot be recorded, the request stays pending, so a request never reads as granted without the access behind it.
- **Refuse** asks for a reason. The asker reads that reason in the portal, so write it for them.

Requests of another organisation do not show in your list and cannot be answered from your account.

## Who may answer

Answering needs the action `portal.answer-access-request`. Out of the box only Nextcloud administrators hold it. The action matrix lives in Portaliq's app setting `actions`: read it with `occ config:app:get portaliq actions`, add the group that should answer to `portal.answer-access-request`, and write it back with `occ config:app:set portaliq actions --value '<the matrix>'`. A colleague without the action gets a refusal, and the request stays pending.

Next: give the team that handles mandates the action, then ask a test account to request access and grant it.
