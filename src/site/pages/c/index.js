// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Slice c (forms and actions) has no screen of its own: its forms and actions
 * live on slice b's contributed pages. Importing this file fills the four
 * places that page leaves for slice c (blockSlots.js); it registers no pages.
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

import { registerBlockSlot } from '../collections/blockSlots.js'

export { default as strings } from './strings.js'

// Fill slice b's places on a contribution page; each part loads on first use.
registerBlockSlot('action', () => import('../../components/c/ActionBlock.vue'))
registerBlockSlot(
	'rowAction',
	() => import('../../components/c/RowActionDialog.vue'),
)
registerBlockSlot('proposals', () => import('../../components/c/ProposalQueue.vue'))
registerBlockSlot(
	'attachedActions',
	() => import('../../components/c/AttachedActions.vue'),
)

/** @type {Record<string, () => Promise<object>>} */
export const pages = {}
