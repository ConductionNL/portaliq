// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// What every widget on the portal's detail page needs: the portal the page
// shows, handed over as `objectData`, and its slug, which every call of
// these widgets addresses the portal by.

export default {
	props: {
		/** The portal the detail page shows. */
		objectData: {
			type: Object,
			default: null,
		},
	},

	computed: {
		/**
		 * The portal's slug.
		 *
		 * @return {string} The slug, or '' without a portal.
		 */
		slug() {
			return String(this.objectData?.slug || '')
		},
	},
}
