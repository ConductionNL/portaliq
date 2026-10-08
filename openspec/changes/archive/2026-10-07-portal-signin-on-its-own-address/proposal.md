# Proposal: portal-signin-on-its-own-address

## Why

Found while testing a primary school parent portal end to end (2026-09-30). The school's portal has slug `wilgenboom` and organisation `default-organisation`, whose DigiD broker is configured.

1. **Opened by its own address, the portal offered no way in.** `/apps/portaliq/portal?portal=wilgenboom` said "No login method is configured for this organisation yet". The login buttons were only looked up from `?org=`, never from the portal's own `organisation`.
2. **With `?org=` added, the DigiD button still failed.** The SPA starts a login with `config.organisationSlug`, and once a portal is resolved that value is the portal's slug. The server looked for a broker for organisation `wilgenboom`, found none and answered the generic failure. Any portal whose slug differs from its organisation could not sign anyone in.
3. **Every resident saw a "Dev-login (test)" button**, also where the server refuses the dev login with a 404.

## What changes

- The runtime config gains `signinOrganisation`: the `?org=` value, or else the resolved portal's own `organisation`. The login buttons and the silent sign-in start with it. The providers come from that organisation too.
- The runtime config gains `devLogin`, true only where the server accepts the dev login (system `debug`, or app config `dev_login_enabled` = `yes`). The SPA shows the button only then.

- A login started from a portal returns to that portal. Before, the callback always returned to `/apps/portaliq/portal` without `?portal=`, so after sign-in the header said "Portaliq" instead of the school portal's title.

## Not changed

- `organisationSlug` keeps meaning the portal's slug for everything else (the `X-Portaliq-Portal` header).
