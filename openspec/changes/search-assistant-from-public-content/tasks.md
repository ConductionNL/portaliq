# Tasks: search-assistant-from-public-content

## Before any code

- [ ] **T01**: Confirm with the hermiq lane the in-process entry point for an anonymous, tool-free, source-scoped conversation (sibling half), and record its class and method in this change's design before T03. Verification: the reference in the PR body; no portaliq code calls hermiq until it exists. — not run: needs hermiq. The adapter names the contract it expects (`OCA\Hermiq\Service\PublicChannelConversation::converse(question, portal, locale, scope, conversationId, tools)`) and stays off while that class is absent.

## The scope and the adapter

- [x] **T02**: `lib/Service/Assistant/PublicSourceScope.php` and `portal.assistant` (`enabled`, `excludedRoutes`) in `lib/Settings/portaliq_register.json`, register version bump (REQ-SAP-002, REQ-SAP-006). Verification: `PublicSourceScopeTest::testOnlyPublishedPublicSchemas`.
- [x] **T03**: `lib/Service/Assistant/PublicAssistantChannel.php`: in-process call, no identity, personal details stripped (REQ-SAP-002, REQ-SAP-004, REQ-SAP-005). Verification: `PublicAssistantChannelTest::testNoIdentityForwarded`, `::testCitizenServiceNumberRemoved`, `::testNoToolsRequested`.
- [x] **T04**: `POST /api/assistant/ask`, `#[PublicPage]`, throttled, 400 on a portal bearer, answer only with sources (REQ-SAP-001, REQ-SAP-002). Verification: `PublicAssistantControllerTest::testBearerIsRefused`, `::testAnswerWithoutSourceBecomesAbstention`.

## The widget

- [ ] **T05**: `src/site/components/AssistantBlock.vue` as widget `assistant`, registered only when enabled; disclosure, input note, answer with sources, abstention, removal notice (REQ-SAP-001, REQ-SAP-003, REQ-SAP-006). Verification: `tests/e2e/search-assistant-from-public-content.spec.ts` against a stubbed hermiq entry point. Built: the block, the gate and `tests/search-assistant.spec.mjs`. — not run: the e2e spec needs a live instance with hermiq.
- [x] **T06**: A traffic event `assistant.asked` (stored as `assistant_asked`, the repo spelling) with no text (design D5). Verification: `TrafficIngestServiceTest` accepts it without a text field.

## Docs, strings and validation

- [x] **T07**: English and Dutch strings for every sentence in design D3 and D4; a docs page for organisations on what the assistant reads, where the model runs, and how to turn it on. Verification: `npm run lint`, `test:l10n`.
- [ ] **T08**: `openspec validate search-assistant-from-public-content --strict`. — not run: the openspec CLI is not installed here.
