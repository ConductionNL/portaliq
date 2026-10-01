// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The parts of slice e that other screens mount. Each is a lazy chunk, so
// importing this file adds almost nothing to the site bundle.
//
// @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md

import { defineAsyncComponent } from 'vue'

/**
 * The citizen's own case: the server's writable set, amend, add a document,
 * documents grouped decision first, download, withdraw. The `citizenCase`
 * block of a contribution page (slice b) mounts it.
 *
 * Props:
 * - `collection` (object, required): the manifest collection the case lives in.
 * - `row` (object|null): the selected case row; `row._mandate.id` reads the
 *   case under that mandate. Without a row it says "Select a case.".
 * - `api` (object, required): the portal API adapter (`fetchCitizenCase`,
 *   `amendCitizenCase`, `addCitizenDocument`, `downloadCitizenDocument`,
 *   `withdrawCitizenCase`).
 * - `t` (function, required): the translator.
 * - `locale` (string): dates in this language, else the page's `<html lang>`.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-citizen-must-work-on-their-own-case-req-srp-042
 */
export const CitizenCase = defineAsyncComponent(() => import('./CitizenCase.vue'))

/**
 * "Acting for" for the site header. Mount it with only `t`: it reads and
 * writes the shared acting-for store (./actingFor.js) and renders nothing
 * until "My cases" has learned that the person holds a mandate.
 *
 * Props: `t` (function, required); `mandates` (array) and `value` (string)
 * override the store. Emits `change(id)`.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-act-for-someone-else-req-srp-041
 */
export const ActingForSwitcher = defineAsyncComponent(
	() => import('./ActingForSwitcher.vue'),
)

/**
 * The prompt for a missing e-mail address. Show it while
 * `contactPromptWanted(session)` from src/site/pages/e/index.js holds.
 *
 * Props: `t` (function, required), `navigate` (function: "Go to My account"
 * calls `navigate('__account__')`). Emits `dismiss` after hiding itself for
 * the session.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-manage-their-own-account-req-srp-037
 */
export const ContactPrompt = defineAsyncComponent(
	() => import('./ContactPrompt.vue'),
)
