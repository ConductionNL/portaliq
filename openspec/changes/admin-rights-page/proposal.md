# Proposal: admin-rights-page

## Why

The board [portaliq/PtBeheerdersrechten](https://identity.conduction.nl/screens/board?id=portaliq/PtBeheerdersrechten) gives an in-app page "Beheerdersrechten" that lists the admin groups with their members and what they may do. Portaliq stores which group may perform which action (operate-roles-for-content-and-actions) and edits it in the administration settings, but nowhere shows it per group.

## What Changes

- `GET /api/settings/action-groups`, admin only: every group that holds a right, with its name, member count and the rights it holds. Administrators hold all rights and are not a row.
- Page `/admin-rights` "Beheerdersrechten" under Geavanceerd, for administrators: the groups as a list, manifest config over nextcloud-vue's `record-list` with an endpoint source.

## Not in this change

The board's edit card per group, topics per group, the two-step sign-in reminder and the audit log with its pills: the rights are edited in the administration settings, and topics, a sign-in policy per group and an admin audit log do not exist yet. Each is a change of its own.
