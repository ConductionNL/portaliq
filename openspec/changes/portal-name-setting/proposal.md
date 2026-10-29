# Proposal: portal-name-setting

## Why

dossiq writes a line for each message a case sent; the board DqZaakContact says "Ontvangstbevestiging via Mijn
Zuiddrecht". dossiq has no way to know what residents call the portal, so it said "via het portaal". The name is
the portal's own area, which a site already declares as `accountLabel`.

## What changes

- portaliq keeps the portal's name in its app config under `portalName`, readable by other apps. An administrator
  may set it (`occ config:app:set portaliq portalName --value=...`).
- Installing an example site fills it from the site's `accountLabel` when no name is set yet, so the Zuiddrecht
  example names it "Mijn Zuiddrecht".
