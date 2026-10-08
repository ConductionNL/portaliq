---
kind: code
depends_on: [hermiq-ai-tooling, portal-federated-search]
---

# Proposal: search-assistant-from-public-content

## Why

A resident wants to know when the waste collection moves for a holiday, or
what they need to bring for a new passport. The answer is on the site, three
clicks deep. Search returns a list of pages; a resident who types a question
wants an answer, with the page it came from.

Portaliq matrix, row `dem-rm-chatbot`, "Ask a chatbot on the portal a
question and get an answer drawn from the organisation's own content.",
origin `roadmap`, <https://www.liferay.com/roadmap>. Rated `no`,
`built.state` `none`. Its `built.evidence`, verbatim:

> no chatbot or conversational frontend: grep for 'chatbot', 'llm' and 'assistant' in src/portal, src/site and lib/ finds only report-thread messages (lib/Controller/ReportController.php:322), which are staff and reporter messages, not an AI

One competitor is rated `yes`, `liferay-dxp`, verbatim:

> https://learn.liferay.com/w/ai-hub/chatbots 'A chatbot is a conversational frontend for your agents ... deploy it as a chat widget on Liferay DXP pages or external sites'; AI Hub is listed under Now on https://www.liferay.com/roadmap.

The lane recorded the row as `build`: a roadmap demand row plus one
competitor rated `yes`.

## What changes

- **An "Ask a question" widget on the public site.** A visitor types a
  question and gets a short answer drawn from the organisation's published
  content, with links to the pages and publications it used.
- **Public content only, by construction.** The widget is anonymous. It
  never sends a portal session, a subject reference or anything from a
  resident's own records. The sources are the portal's published pages and
  glossary and its published publications, and nothing else.
- **It says it is an AI, and it says when it does not know.** The widget
  states that answers come from an AI assistant and can be wrong. When no
  source supports an answer, it says so and points to the contact page,
  instead of guessing.
- **It cannot act.** No tools, no filing, no writes. Asking it to do
  something gets an answer in words and a link to the right form.
- **The model does not run in portaliq.** Hermiq runs the conversation and
  the model; OpenRegister holds the search substrate. Portaliq owns the
  widget, the channel adapter and the choice of what is public.

## Rows this closes

| Matrix | Row id | Row name | Own rating | What is missing |
|---|---|---|---|---|
| portaliq | `dem-rm-chatbot` | Ask a chatbot on the portal a question and get an answer drawn from the organisation's own content. | no | A resident-facing assistant over the portal's public content, with sources, disclosure and abstention. |

## Existing work it builds on

- `hermiq-ai-tooling` (open, this repo): MCP tools serve staff only and are
  never reachable at the portal edge. This change keeps that line: the
  public assistant has no tools at all.
- `portal-federated-search` (open): the public search block, next to which
  the widget sits, and the published publications it searches.
- `portaliq-cms` (spec): published pages and glossary terms per portal.
- hermiq `case-assistant-surface` (spec, done): the precedent for a
  tool-free surface by construction.
- hermiq `a-conversational-intake-that-files-for-the-citizen` (open): its
  D4 says channel adapters belong to the apps that own the channels and
  hermiq holds the conversation.
- hydra ADR-034, amendment 2026-07-05: hermiq is the sole orchestrator and
  OpenRegister provides the retrieval substrate.

## Out of scope

- Questions about a resident's own case, messages or tasks. That is a
  different product with a different risk, and the intake conversation in
  hermiq is where a filing assistant belongs.
- Filing a request from the conversation.
- Choosing or hosting the model.
- An assistant in the signed-in portal.

## Sibling halves

- **ConductionNL/hermiq** owes a server-side entry point for an anonymous,
  tool-free conversation grounded on sources the calling app names. Its
  only conversational surface on `development` for other apps,
  `POST /api/assistant/converse`, requires an authenticated Nextcloud user
  and takes caller-supplied `contextData` with no retrieval ("No tools, no
  RAG orchestration"). Hermiq also owes semantic retrieval: its open
  `vector-rag` change records that `ContextRetrievalHandler` degrades to
  keyword search because OpenRegister publishes no vector facade.
- **ConductionNL/openregister** owes that public vector-search facade, as
  hermiq's `vector-rag` defines it.
- Neither is written here. Portaliq's side ships behind a setting that stays
  off until hermiq's entry point exists.
