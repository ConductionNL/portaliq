## ADDED Requirements

### Requirement: A Woo request form is delivered to dossiq (REQ-WID-001)

`PortalWooRequestDelivery::deliver(array $answers, string $submittedAt): array` SHALL check
`IAppManager::isInstalled('dossiq')`, SHALL locate `OCA\Dossiq\Portal\PortalContributionProvider`
through `PortalProviderLocator::locate('dossiq')`, and SHALL call
`receiveWooRequest($answers, $submittedAt)` on it. It SHALL read the answer keys `outcome`,
`requestId`, `reference`, `dueAt` and `message` exactly as it reads opencatalogi's today: `armed` only
with a due date, and no date on any other outcome. It SHALL NOT locate or call opencatalogi.

#### Scenario: A portal form becomes a dossiq Woo case with a running term
- **GIVEN** dossiq installed with `receiveWooRequest()`, and a form binding with `deliverTo: wooRequest`
- **WHEN** a resident sends the form on 2026-11-27 and the delivery job runs on 2026-11-30
- **THEN** dossiq's provider SHALL receive the answers and `2026-11-27` as the moment it was sent
- **AND** the submission SHALL be registered with dossiq's case number as `externalReference` and `dueAt` 2026-12-28

#### Scenario: opencatalogi is never asked
- **GIVEN** both dossiq and opencatalogi installed
- **WHEN** a Woo form is delivered
- **THEN** no opencatalogi provider SHALL be located or called

### Requirement: Without dossiq there is no Woo intake (REQ-WID-002)

When dossiq is not installed, or its provider has no `receiveWooRequest()`, or the call throws, the
outcome SHALL be `unavailable` with the sentence that Woo requests are handled by dossiq and dossiq is
not installed (or cannot receive them), and the submission SHALL be marked failed with that reason. No
date SHALL be quoted. The form binding screen in the admin SHALL show the same sentence on every
binding with `deliverTo: wooRequest` while dossiq is missing.

#### Scenario: No dossiq, no request, no fallback
- **GIVEN** opencatalogi installed and dossiq not installed
- **WHEN** a Woo form is delivered
- **THEN** the outcome SHALL be `unavailable` naming dossiq, the submission SHALL be failed, and opencatalogi SHALL NOT be called

#### Scenario: The administrator is told first
- **GIVEN** dossiq not installed and a binding with `deliverTo: wooRequest`
- **WHEN** an administrator opens the form bindings
- **THEN** that binding SHALL say Woo requests need dossiq, which is not installed

### Requirement: The portal's default Woo entry is dossiq's action (REQ-WID-003)

Where portaliq provisions or suggests a Woo request entry (the home tile, the Woo page), it SHALL use
dossiq's declared contribution action `startWooVerzoekAlgemeen` when dossiq is installed, and SHALL
offer no Woo request entry when it is not.

#### Scenario: The tile starts dossiq's request
- **GIVEN** dossiq installed and declaring `startWooVerzoekAlgemeen`
- **WHEN** a portal's home page is provisioned
- **THEN** its Woo tile SHALL open dossiq's action, posting to `/index.php/apps/dossiq/api/portal/woo-verzoek`
