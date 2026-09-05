// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// What every Traffic widget needs (portal-traffic-analytics): the shared
// report store, the selected portal, whether it is measured at all, and
// the folded summary. The "not measured" and "no data yet" states are
// decided HERE, once, because a zero and an unmeasured are different
// facts and five widgets deciding it five ways is how one of them ends
// up drawing an empty chart for a portal that was never instrumented.

import { isMeasured } from '../lib/trafficSummary.js'
import { useTrafficReportStore } from '../store/traffic.js'

export default {
	computed: {
		/**
		 * The shared report store.
		 *
		 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-traffic-page-must-show-what-was-measured-and-say-when-it-was-not
		 * @return {object} The store.
		 */
		report() {
			return useTrafficReportStore()
		},

		/**
		 * The selected portal, or null.
		 *
		 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-traffic-page-must-show-what-was-measured-and-say-when-it-was-not
		 * @return {object|null} The portal.
		 */
		portal() {
			return this.report.portal
		},

		/**
		 * Whether the selected portal measures traffic.
		 *
		 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-traffic-page-must-show-what-was-measured-and-say-when-it-was-not
		 * @return {boolean} True when `traffic.enabled`.
		 */
		measured() {
			return isMeasured(this.portal)
		},

		/**
		 * The folded summary for the range.
		 *
		 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
		 * @return {object} See `summarise`.
		 */
		summary() {
			return this.report.summary
		},

		/**
		 * Whether anything is still loading.
		 *
		 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-traffic-page-must-show-what-was-measured-and-say-when-it-was-not
		 * @return {boolean} True while loading.
		 */
		loading() {
			return this.report.loadingPortals || this.report.loadingRecords
		},

		/**
		 * Which empty state to show, if any: 'not-measured' for a portal
		 * with measurement off, 'no-data' for a measured portal with no
		 * rollup in the range, '' when there is something to draw.
		 *
		 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-traffic-page-must-show-what-was-measured-and-say-when-it-was-not
		 * @return {string} The state.
		 */
		emptyState() {
			if (this.loading) {
				return ''
			}
			if (!this.portal || !this.measured) {
				return 'not-measured'
			}
			if (!this.summary.hasData) {
				return 'no-data'
			}
			return ''
		},
	},
}
