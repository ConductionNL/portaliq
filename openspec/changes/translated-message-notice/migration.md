# Migration: translated-message-notice

## Current State

Register 0.35.1; `portalAccount` 0.9.0 without a language preference; `guardianMessage` 0.1.0 with `body` only.

## Target State

Register 0.36.0; `portalAccount` 0.10.0 with optional `messageLanguage`; `guardianMessage` 0.2.0 with optional, server-managed `translations`.

## Migration Class

```
Version: none
File: none
Key operations:
- No Nextcloud migration class. The existing register import is gated on info.version, which moves to 0.36.0 with components.registers.portaliq.version.
```

## Migration Steps

1. The import adds `messageLanguage` to `portalAccount` and `translations` to `guardianMessage`.
2. Nothing is back-filled: an empty preference means "show as written", and translations are made on first read.

## Data Impact

No row changes on upgrade. Rows gain the fields as guardians set a language and read threads.

## Rollback Procedure

Revert the PR. Extra fields stay in stored rows and are ignored.

## Validation

- `vendor/bin/phpunit --filter PortaliqRegisterConfigTest` pins the versions.
- After upgrade, `GET /portal/api/identity/details` returns `messageLanguage` for a signed-in account.
