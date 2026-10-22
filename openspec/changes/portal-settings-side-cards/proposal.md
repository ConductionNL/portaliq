# portal-settings-side-cards

## Why

The PtPortalInstellingen board draws two more cards in the settings tab's side column: "Berichtenbox van MijnOverheid"
(a switch "Brieven en besluiten ook naar de Berichtenbox sturen") and "Als app installeren" (a switch "Bezoekers kunnen de
portal installeren op hun telefoon"). Boards are canon.

Both have backing already:
- The Berichtenbox channel exists (inbox-berichtenbox-channel): an organisation offers it through `messageBox`
  `{sourceId, label}` in its presentation settings, and a resident can switch it off. Only an administrator screen to
  switch the offer was missing; it lived in app config with no control.
- The portal is installable (parent-pwa-installability): `PortalManifestController` serves a web app manifest and a
  service worker for every portal. There was no way to switch that off per portal.

## What changes

- **Berichtenbox card**: a side card on the settings tab whose switch turns the portal's organisation's message box
  offer on or off, through a new admin endpoint `GET|PUT /api/portals/{slug}/message-box`. Off keeps the configured
  integriq source and label aside, so on restores them; with no source ever configured the switch is disabled and the
  card says to set up the digital post source in integriq first. No recipient or BSN is stored.
- **Install card**: a portal field `installable` (default true) behind a side card switch; with it off the manifest
  route answers 404, so the browser offers no install.
