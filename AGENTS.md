# AGENTS.md

Guidance for coding agents working in this repository. Humans can read it too; the
longer version lives in [README.md](README.md) and [CONTRIBUTING.md](CONTRIBUTING.md).

## What this app is

Portaliq is the shared external portal for Nextcloud apps: clients (citizens) and
suppliers (companies) get one white-label portal to see and act on their records
across apps. It reads data through
[OpenRegister](https://github.com/ConductionNL/openregister), renders each app's
registered portal contribution, and owns the external sign-in edge, the portal shell
and the unified inbox; employees stay internal.

## Setup

```bash
composer install     # also points git at .githooks (post-install-cmd)
npm install          # also points git at .githooks (prepare)
```

Run it locally with Docker in one of two ways, both described in the README:

- Evaluation stack from this repo: `docker compose -f docker-compose.yml up -d --build`,
  then open http://localhost:8080/apps/portaliq/ (user `admin`, password `admin`).
  `docker compose -f docker-compose.yml down -v` removes it.
- Shared development environment: `docker compose -f ../openregister/docker-compose.yml up -d`
  from a sibling OpenRegister checkout, with this app mounted into it. The `js/` build
  output is not committed, so build before enabling the app.

## Build

```bash
npm run build        # all bundles: admin (build:admin), public site (build:site), traffic (build:traffic)
npm run dev          # development build of the admin bundle
npm run watch        # admin bundle, rebuilds on change
npm run watch:site   # public site bundle, rebuilds on change
```

## Test

```bash
composer test:unit   # PHPUnit, tests/Unit (phpunit.xml)
npm run check:specs  # the frontend suite: JSON, manifest and register validation plus the node --test specs in tests/
npm run test:e2e     # Playwright, tests/e2e (needs a running Nextcloud)
```

A single frontend spec runs with its own script, for example
`npm run check:manifest` or `npm run check:schema-form`, or directly with
`node --test tests/<name>.spec.mjs`.

Playwright has no default target, on purpose. Set `PLAYWRIGHT_BASE_URL` to the
instance the suite should run against, for example
`PLAYWRIGHT_BASE_URL=http://localhost:8095 npm run test:e2e`; see the header of
`playwright.config.ts`.

## Lint and quality

```bash
composer check:strict   # lint, phpcs, phpmd, psalm, phpstan, tests
composer check          # quicker: lint, phpcs, psalm, unit tests
composer cs:fix         # php-cs-fixer
composer phpcs:fix      # phpcbf
npm run lint            # ESLint
npm run stylelint       # Stylelint
npm run format          # Prettier check
npm run check:specs     # run before committing any register or manifest change
```

`composer check:strict` is the PHP gate CI enforces; the `Spec Validation` workflow
runs `npm run check:specs` on every push and pull request.

## OpenSpec flow

Work is specified before it is built, with [OpenSpec](openspec/config.yaml):

1. A change starts in `openspec/changes/<change-name>/` with a `proposal.md`,
   `tasks.md`, delta specs under `specs/` and, when it makes a design decision,
   a `design.md`.
2. When it is built, its requirements are merged into `openspec/specs/<capability>/`.
3. The finished change moves to `openspec/changes/archive/`.

## Architecture decisions

`openspec/architecture/` holds the architecture records, named `adr-NNN-<slug>.md`;
its README explains the format and when to write one.

## Conventions

- **Procedures are configuration, not code.** `.procedure-code-baseline.json` pins the
  count of procedure-named files at 0; the quality gate fails when one appears. Name
  code by what it does and put procedure specifics in the register, the manifest or
  configuration.
- **Pages are manifest config.** The admin app builds its routes from
  `src/manifest.json`; add a page there and run `npm run check:manifest`.
- **Data lives in OpenRegister.** The register and its schemas ship as JSON in
  `lib/Settings/portaliq_register.json`, validated by `npm run check:register`.
- **Every user-visible string is translated** with `t('portaliq', ...)`.

## Commits

[Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/): `feat:`, `fix:`,
`docs:`, `refactor:`, `chore:`. Present tense, imperative mood, first line at most 72
characters. Pull requests target `development`.
