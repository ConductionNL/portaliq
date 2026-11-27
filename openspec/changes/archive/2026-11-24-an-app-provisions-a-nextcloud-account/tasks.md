# Tasks: an app provisions a nextcloud account

- [x] 1. `NextcloudAccountProvisioner` (checks, idempotent write, refusals).
- [x] 2. `PortalAccountProvisionRequestedEvent`: `nextcloudUid`, `portal`; listener branch.
- [x] 3. `NextcloudAccountProvisionerTest` through the real event and listener.
- [x] 4. learniq (FIX-L) dispatches the event for each training participant on example-set load. — built in learniq#1836: `ExamplePortalAccountGrants` dispatches `PortalAccountProvisionRequestedEvent` with `nextcloudUid` and `portal` for each declared account with a portal audience (training-deelnemer-151 on warmtepompacademie), called from `occ learniq:example-set:load`; tested in learniq `tests/Unit/Portal/ExamplePortalAccountGrantsTest.php`.
