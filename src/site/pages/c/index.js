// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Slice c (forms and actions) has no screen of its own: its forms and actions
 * live on slice b's contributed pages, through `src/site/components/c/`. So it
 * registers no pages, only its strings.
 *
 * @typedef {object} SitePageProps
 * @property {object} session The session as `/portal/api/session` returns it.
 * @property {object} portal The portal record.
 * @property {object} api The portal api helper.
 * @property {(key: string, vars?: Record<string, string|number>) => string} t The translator.
 * @property {(key: string, params?: object) => void} navigate Go to another page.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-schema-form-must-render-only-whitelisted-fields-req-srp-022
 */

export { default as strings } from './strings.js'

/** @type {Record<string, () => Promise<object>>} */
export const pages = {}
