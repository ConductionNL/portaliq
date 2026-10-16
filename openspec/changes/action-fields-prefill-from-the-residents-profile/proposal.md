# Proposal: action-fields-prefill-from-the-residents-profile

## Why

A signed-in resident who starts a request through a contributed action form (for example dossiq's Woo request, step 3 "Uw gegevens") types their own name and e-mail address again, although the portal already knows them. Seen in the Zuiddrecht go-live rehearsal on 2026-10-15. The intake form object already prefills its applicant block (REQ-PIFO-003); a contributed action had no way to ask for the same.

## What changes

- An action field config MAY declare `prefill: "name"` or `prefill: "email"`. Portaliq keeps only those two values and drops any other.
- The site's action form starts such a field from the signed-in resident's own details (`identity/details`: `displayName`, `email`) when the field is empty, after a saved draft is opened. The resident can change the value.
- Without a session, or when the detail is empty, the field stays empty.

## Not changed

- What is sent: the field travels as any other whitelisted field.
- Which fields a form has: `prefill` never adds a field to the whitelist.
- The intake form object's own applicant prefill.

## Depends on

An app declares `prefill` on its fields; dossiq's Woo request does so in its own change.
