# Proposal: endpoint-forward-draft-schema

## Why

live-learniq-1 (25 Nov 2026, 20:08Z): a workplace trainer cannot save a werkproces assessment halfway (learniq site-workplace-trainer-portal-design T9). portaliq offers "Opslaan als concept" only on create actions, because it keeps a draft as the destination record itself in status draft (REQ-SMF-021) and an endpoint-forward action has no register or schema. learniq's `createWerkprocesAssessment` became endpoint-forward in an-invited-trainer-may-assess T2 (the assertion carries the sign-in level), so it lost its draft. Ruben decided (25 Nov 2026 21:50 CET): "an endpoint-forward action may declare draftSchema; portaliq saves the draft there through OpenRegister, the endpoint receives the final body."

## What changes

- An action that is not a create, names an `endpoint` and keeps a `draft` MAY declare `draftSchema: {register, schema}`. The normaliser keeps it only there, and only as two slugs.
- portaliq keeps the resident's unfinished answers on that schema through OpenRegister's own drafts, exactly as for a create action: the same owner, retention, lookup by the action's defaults, and "Mijn concepten" listing. The draft is stamped with the action's `subjectField` (else `scopeField`, else `subjectRef`).
- The endpoint receives only the final body, as before. Once it answers 2xx, portaliq discards the draft; a refused body keeps it; a failing discard never changes the answer (the draft then ends with its retention).
- The site form offers the save button and resumes a draft for such an action.

## Out of scope

- The learniq declaration and its draft schema (learniq, its own PR).
- Live check: the learniq live lane (mbo-trainer-flows "saving halfway").
