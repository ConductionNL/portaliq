// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The read a collection widget of the Mijn omgeving group shares
// (site-nlds-widget-palette T9, REQ-SNW-012): the props a placement names,
// the shell's data the grid hands down after them, and one read of the
// named collection through the shell's subject-scoped api. Signed out the
// shell hands down nothing, so `signedIn` is false and nothing is read.

import { ownCollection, ownRows, ownShell } from './ownData.js'

export default {
	props: {
		/** The heading. */
		label: { type: String, default: '' },
		/** The app whose collection the widget shows. */
		app: { type: String, default: '' },
		/** The collection's id in that app's contribution. */
		collection: { type: String, default: '' },
		/** How many rows, at most. */
		limit: { type: [Number, String], default: 5 },
		/** The shell's signed-in data; handed down by the grid, never authored. */
		mijn: { type: Object, default: null },
		/** The portal's ways in, for the signed-out prompt. */
		signInRoutes: { type: Array, default: () => [] },
		/** The page language, for the signed-out prompt. */
		locale: { type: String, default: 'nl' },
		/** The record id the route carries; handed down by the grid, never authored. */
		routeParam: { type: String, default: '' },
	},

	emits: ['navigate'],

	data() {
		return { rows: [], loading: false, failed: false }
	},

	computed: {
		/**
		 * @return {boolean} Whether the shell holds a signed-in visitor.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		signedIn() {
			return ownShell(this.mijn) !== null
		},

		/**
		 * @return {object|null} The collection the placement names, as the visitor's contribution declares it.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		ownCollection() {
			return ownCollection(this.mijn, this.app, this.collection)
		},

		/**
		 * @return {number} The limit, between 1 and 20.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		rowLimit() {
			const count = Math.trunc(Number(this.limit))
			return count > 0 ? Math.min(count, 20) : 5
		},
	},

	watch: {
		/**
		 * The shell answers the contributions after the session: read once
		 * they arrive, and again when a sign-in or sign-out replaces them.
		 *
		 * @return {void}
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		'mijn.contributions': function () {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the named collection, once the shell holds a visitor.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		async load() {
			if (!this.signedIn || !this.ownCollection) {
				return
			}
			this.loading = true
			this.failed = false
			const rows = await ownRows(this.mijn, this.ownCollection)
			this.loading = false
			this.failed = rows === null
			this.rows = rows || []
		},
	},
}
