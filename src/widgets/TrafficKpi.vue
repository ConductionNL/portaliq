<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  TrafficKpi: one headline number of the Traffic page as its own KPI card
  (portal-traffic-kpi-cards). The four used to sit together inside the
  overview card.

  It reads the shared report store, so it follows the portal, period and
  segment chosen on the overview. It carries no period picker of its own:
  the page has one period, and a second control would read as a second
  period. An unmeasured portal reads "Not measured" and a measured portal
  without records reads "No traffic recorded yet", never a zero.

  The manifest supplies `metric` (a key of the summary totals), `label`,
  `variant` and `testId`.

  @spec openspec/changes/portal-traffic-kpi-cards/specs/portal-traffic-kpi-cards/spec.md#requirement-the-traffic-page-must-show-its-four-headline-numbers-as-kpi-cards
-->
<template>
	<CnStatsBlock
		:title="title"
		:count="count"
		:countLabel="rangeLabel"
		:emptyLabel="emptyLabel"
		:showZeroCount="emptyState === ''"
		:loading="loading"
		:variant="content.variant || 'default'"
		:data-testid="content.testId || null" />
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import trafficWidgetMixin from './trafficWidgetMixin.js'

export default {
	name: 'TrafficKpi',

	components: {
		CnStatsBlock,
	},

	mixins: [trafficWidgetMixin],

	props: {
		/** The widget's manifest content. */
		content: {
			type: Object,
			default: () => ({}),
		},
	},

	computed: {
		/**
		 * The manifest label in the reader's language.
		 *
		 * @spec openspec/changes/portal-traffic-kpi-cards/specs/portal-traffic-kpi-cards/spec.md#requirement-the-traffic-page-must-show-its-four-headline-numbers-as-kpi-cards
		 * @return {string} The title.
		 */
		title() {
			return this.content.label ? this.t('portaliq', this.content.label) : ''
		},

		/**
		 * The metric's total for the selection, or 0 while there is none.
		 *
		 * @spec openspec/changes/portal-traffic-kpi-cards/specs/portal-traffic-kpi-cards/spec.md#requirement-the-traffic-page-must-show-its-four-headline-numbers-as-kpi-cards
		 * @return {number} The total.
		 */
		count() {
			if (this.emptyState !== '') {
				return 0
			}
			return Number(this.summary.totals[this.content.metric]) || 0
		},

		/**
		 * What the card says instead of a number.
		 *
		 * @spec openspec/changes/portal-traffic-kpi-cards/specs/portal-traffic-kpi-cards/spec.md#requirement-the-traffic-page-must-show-its-four-headline-numbers-as-kpi-cards
		 * @return {string} The label.
		 */
		emptyLabel() {
			if (this.emptyState === 'no-data') {
				return this.t('portaliq', 'No traffic recorded yet')
			}
			return this.t('portaliq', 'Not measured')
		},
	},
}
</script>
