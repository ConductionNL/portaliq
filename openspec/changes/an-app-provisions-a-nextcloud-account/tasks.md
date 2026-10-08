# Tasks: an app provisions a nextcloud account

- [x] 1. `NextcloudAccountProvisioner` (checks, idempotent write, refusals).
- [x] 2. `PortalAccountProvisionRequestedEvent`: `nextcloudUid`, `portal`; listener branch.
- [x] 3. `NextcloudAccountProvisionerTest` through the real event and listener.
- [ ] 4. learniq (FIX-L) dispatches the event for each training participant on example-set load.
