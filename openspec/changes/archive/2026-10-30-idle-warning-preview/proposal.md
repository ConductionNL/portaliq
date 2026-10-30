## Why

The SessieVerloopt board draws the idle warning, the dialog that appears just before an idle portal session signs out. The warning only appears after the idle window, which is at least 5 minutes, so a board measurement or a test cannot reach it within one run.

## What Changes

- A new app flag, `session_idle_preview_enabled`, is `no` by default.
- When the flag is `yes`, the session answer carries `idlePreview: true`. The site then opens the idle warning immediately for a page whose address holds `idleWarning=now`.
- When the flag is not set, nothing changes: the address parameter is ignored and the session answer carries `idlePreview: false`.
- The flag is for development and test instances only. The warning it opens is the real dialog, and its buttons behave as they always do.
