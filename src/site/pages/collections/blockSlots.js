// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// THE PLACES ON A CONTRIBUTION PAGE THAT OTHER SLICES FILL.
//
// The collection pages (slice b) render tables, detail cards, item lists and
// timelines. Some blocks and parts belong to another slice of the site-parity
// change, and this slice leaves a named place for them instead of building
// them:
//
//   name             slice  what goes there                     props
//   action           c      an `action` or `cta` block: a form   block, action, contribution, api, t, locale
//                           for create/update, else a button
//   rowAction        c      the confirm, sign or decline step    action, viewAction, dialog, collection, row, api, t, locale
//                           of an endpoint row action
//   proposals        c      a record's change proposals          action, row, api, t, locale
//   attachedActions  c      another app's actions on a record    collection, row, api, t, locale
//   timedTask        d      a timed task (a test with a clock)   collection, app, attempts, api, t, locale
//   citizenCase      e      one case of the resident             collection, row, api, t, locale
//
// Events a filler may emit: `created(object, {register, schema})` after a
// write, so every collection on that schema loads again and the unread count
// refreshes; `action(action)` to run an endpoint action; `close` and `done`
// from a row action step.
//
// HOW A SLICE FILLS ONE. Call `registerBlockSlot(name, loader)` with a lazy
// loader, from that slice's own index.js:
//
//     registerBlockSlot('timedTask', () => import('./TimedTaskView.vue'))
//
// A place nothing fills renders an empty, hidden marker
// (`data-testid="collections-slot-<name>"`) and nothing a resident sees.
//
// Imports nothing, so tests/site-collections.spec.mjs runs it as node.
//
// @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-contribution-page-must-render-its-blocks-req-srp-014

/** Every place a slice can fill. */
export const BLOCK_SLOTS = Object.freeze([
	'action',
	'rowAction',
	'proposals',
	'attachedActions',
	'timedTask',
	'citizenCase',
])

const loaders = new Map()

/**
 * Fill a place with a component loader.
 *
 * @param {string} name One of BLOCK_SLOTS.
 * @param {() => Promise<object>} loader Loads the component.
 * @return {void}
 */
export function registerBlockSlot(name, loader) {
	if (!BLOCK_SLOTS.includes(name)) {
		throw new TypeError(
			`"${name}" is not a place on a contribution page; use one of ${BLOCK_SLOTS.join(', ')}`,
		)
	}
	if (typeof loader !== 'function') {
		throw new TypeError(`The place "${name}" needs a loader function`)
	}
	loaders.set(name, loader)
}

/**
 * The loader that fills a place, or null.
 *
 * @param {string} name The place.
 * @return {(() => Promise<object>)|null}
 */
export function blockSlotLoader(name) {
	return loaders.get(name) || null
}
