<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  TrafficDaily — page views, sessions and visitors per day for the last
  30 days (portal-traffic-analytics).

  Renders NOTHING chart-shaped for a portal that is not measured: an
  empty chart is a zero, and a zero is a claim.

  @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-traffic-page-must-show-what-was-measured-and-say-when-it-was-not
-->
<template>
	<div class="traffic-daily" data-testid="traffic-daily">
		<TrafficEmptyState :state="emptyState" />
		<CnChartWidget
			v-if="emptyState === '' && !loading"
			type="area"
			:series="series"
			:categories="summary.series.dates"
			:height="240"
			legend
			data-testid="traffic-daily-chart" />
	</div>
</template>

<script>
import { CnChartWidget } from '@conduction/nextcloud-vue'
import TrafficEmptyState from './TrafficEmptyState.vue'
import trafficWidgetMixin from './trafficWidgetMixin.js'

export default {
	name: 'TrafficDaily',

	components: {
		CnChartWidget,
		TrafficEmptyState,
	},

	mixins: [trafficWidgetMixin],

	computed: {
		/**
		 * The three series the chart draws.
		 *
		 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
		 * @return {Array<{name: string, data: Array<number>}>} The series.
		 */
		series() {
			return [
				{
					name: this.t('portaliq', 'Page views'),
					data: this.summary.series.pageViews,
				},
				{
					name: this.t('portaliq', 'Sessions'),
					data: this.summary.series.sessions,
				},
				{
					name: this.t('portaliq', 'Visitors'),
					data: this.summary.series.visitors,
				},
			]
		},
	},
}
</script>

<style scoped>
.traffic-daily {
	height: 100%;
	padding: 4px;
	box-sizing: border-box;
}
</style>
