/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Portaliq's change-proposal review surface, as an OpenRegister leaf.
 *
 * The render half of ONE registration whose server half is
 * `lib/Listener/RegisterProposalLeavesListener.php`. Both halves carry the
 * same id, the same surfaces and the same render mode; a difference between
 * them is a leaf whose behaviour depends on which half the consumer read.
 *
 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
 */
import { translate as t } from '@nextcloud/l10n'
import { createApp } from 'vue'
import ProposalQueueWidget from './ProposalQueueWidget.vue'

/**
 * The integration id a consuming app places.
 *
 * @type {string}
 */
export const PROPOSAL_QUEUE_INTEGRATION_ID = 'portaliq-change-proposal-queue'

/**
 * Every render surface this leaf targets. Duplicated verbatim by
 * `RegisterProposalLeavesListener::QUEUE_SURFACES`.
 *
 * @type {string[]}
 */
export const SURFACES = ['detail-page', 'single-entity']

/**
 * Vue apps this leaf has mounted, keyed by the host-owned element.
 *
 * @type {Map<Element, import('vue').App>}
 */
const mountedApps = new Map()

/**
 * Root the review surface at a host-owned element.
 *
 * Portaliq is Vue 3 and a host may be Vue 2.7, so the host hands over a bare
 * element and each side runs its own framework across it. Idempotent per
 * element.
 *
 * @param {Element} el    Host-owned container element.
 * @param {object}  props Forwarded context: { register, schema, objectId }.
 * @return {void}
 */
export function mount(el, props) {
	if (el === undefined || el === null || mountedApps.has(el) === true) {
		return
	}
	const context = props || {}
	const app = createApp(ProposalQueueWidget, {
		register: String(context.register || ''),
		schema: String(context.schema || ''),
		objectId: String(context.objectId || ''),
	})
	app.mount(el)
	mountedApps.set(el, app)
}

/**
 * Destroy the app rooted at `el`.
 *
 * @param {Element} el The element previously passed to `mount`.
 * @return {void}
 */
export function unmount(el) {
	const app = mountedApps.get(el)
	if (app === undefined) {
		return
	}
	mountedApps.delete(el)
	app.unmount()
}

/**
 * The integration descriptor for the review surface.
 *
 * @type {object}
 */
export const proposalQueueLeafDescriptor = {
	id: PROPOSAL_QUEUE_INTEGRATION_ID,
	label: t('portaliq', 'Change proposals'),
	icon: 'FileDocumentEditOutline',
	requiredApp: 'portaliq',
	order: 40,
	group: 'workflow',
	surfaces: SURFACES,
	renderMode: 'mount',
	mount,
	unmount,
	defaultSize: { w: 6, h: 4 },
}

/**
 * Register the leaf on the shared OpenRegister integration registry.
 *
 * Installs a load-order-safe queue stub when OpenRegister's bundle has not
 * installed the registry yet, so a portaliq bundle that loads first is not
 * lost.
 *
 * @param {object} [globalRef] Global to attach to (defaults to `window`).
 * @return {void}
 */
export function registerProposalQueueLeaf(globalRef) {
	const target = globalRef || (typeof window !== 'undefined' ? window : null)
	if (target === null) {
		return
	}

	target.OCA = target.OCA || {}
	target.OCA.OpenRegister = target.OCA.OpenRegister || {}
	target.OCA.OpenRegister.integrations = target.OCA.OpenRegister.integrations || {
		_queue: [],
		register(entry) {
			this._queue.push(entry)
		},
	}

	target.OCA.OpenRegister.integrations.register(proposalQueueLeafDescriptor)
}
