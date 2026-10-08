# Design: search-assistant-from-public-content

Read at portaliq `development` `eeda3fa`, hermiq `development` and hydra
ADR-034.

## Where the model runs, and what portaliq does

Hydra ADR-034, "Hermiq is the sole orchestrator; OpenRegister provides the
RAG substrate and the MCP tool registry": hermiq owns response generation,
its `ContextRetrieval` step, the LLM provider selection and conversation
persistence. The provider (OpenAI, Ollama, Fireworks, or a future Nextcloud
Task Processing driver) is hermiq's setting, subject to hermiq's
`tenant-model-policy` per organisation. Portaliq calls no model.

Portaliq owns three things: the widget, the channel adapter that passes a
question to hermiq and the answer back, and the declaration of which content
is public for a portal.

## What exists

- No assistant, chatbot or LLM code in `src/portal`, `src/site` or `lib/`
  (the matrix's `built.evidence`).
- `src/site/components/WidgetGrid.vue:159-179` `PUBLIC_WIDGETS`, the gate for
  what renders at a public origin.
- `lib/Service/CmsReader.php:170,325` reads a portal's published pages and
  glossary terms.
- hermiq `case-assistant-surface` spec: `POST /api/assistant/converse` for an
  authenticated user, tool-free by construction, grounded in caller-supplied
  `contextData`.
- hermiq `vector-rag` (open): semantic retrieval waits on an OpenRegister
  vector facade.

## D1. The channel adapter is in-process and carries no identity

`lib/Service/Assistant/PublicAssistantChannel.php` calls the entry point
hermiq publishes for channel adapters, in-process, when hermiq is installed
and the entry point exists; otherwise the feature is off. It does not call
hermiq over HTTP, and it does not call any model. What it passes:

- the question text, after D4;
- the portal slug and the visitor's locale;
- the source scope from D2;
- a conversation id hermiq issued earlier in the same visit, if any.

It never passes a portal session, a `subjectRef`, an organisation claim, an
IP address or a traffic visitor id. The route that serves the widget,
`POST /api/assistant/ask`, is `#[PublicPage]`, throttled
(`AnonRateLimit`), and refuses a request that carries a portal bearer with
400, so a signed-in resident's identity cannot reach it by accident.

## D2. Public is a declaration, not a guess

`lib/Service/Assistant/PublicSourceScope.php::for(string $portal)` returns
the sources the assistant may use: the portal's `page` objects with
`status: published`, its `glossaryTerm` objects, and the publications the
public search block reads (OpenCatalogi's published publications). A
portal's `assistant` settings on the `portal` object hold `enabled`
(default false) and an optional list of page routes to leave out. No schema
that holds a resident's data can be added to the scope; the list is a
constant, and a test pins it.

## D3. What the visitor sees

`src/site/components/AssistantBlock.vue`, widget `assistant`, added to
`PUBLIC_WIDGETS` only when the portal's `assistant.enabled` is true.

- Above the input: "Answers come from an AI assistant and can be wrong.
  Check the page it links to." (EU AI Act transparency for a system that
  talks to people.)
- Below the input: "Do not type personal details such as your citizen
  service number."
- An answer shows its text and "Sources" with a link per source.
- An answer without a source is not shown. Instead: "I could not find this
  in our information. You can contact us." with a link to the portal's
  contact page.
- A request to do something ("apply for", "cancel") gets words and, where a
  source is a form page, its link.

## D4. Personal details are stripped before they leave

Before forwarding, the adapter replaces sequences that pass the eleven-test
for a citizen service number, email addresses and Dutch phone numbers with
`[removed]`, and tells the visitor "We removed personal details from your
question." This is a floor, not a guarantee; hermiq's `agent-guardrails`
still apply to the text it receives.

## D5. Nothing about the visitor is kept here

Portaliq stores no question and no answer. Hermiq's `run-audit-log` keeps
the run under hermiq's retention. The traffic layer counts an
`assistant.asked` event with no text, so an organisation sees use, not
content.

## Risks

- **The sibling entry point never ships.** The widget stays unregistered,
  so nothing half-works in public.
- **Keyword retrieval gives poor answers.** Until hermiq's `vector-rag`
  lands, answers rest on keyword retrieval. The abstention in D3 is what
  keeps a poor retrieval from becoming a confident wrong answer.
- **Prompt injection through published content.** An editor's page is
  trusted content; a publication harvested from elsewhere is not. Hermiq's
  guardrails handle the text; the source links let the visitor check.

## What this deliberately does not do

- No tools, no MCP, no writes.
- No personal answers.
- No model configuration in portaliq.
