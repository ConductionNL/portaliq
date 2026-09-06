<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  TrafficJourneys — the ten most travelled steps from one page to the
  next within a session, over the last 30 days (portal-traffic-analytics).

  @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-session-must-be-reconstructable-into-an-ordered-journey
-->
<template>
	<div class="traffic-table" data-testid="traffic-journeys">
		<TrafficEmptyState :state="emptyState" />
		<table
			v-if="emptyState === ''"
			class="traffic-table__table"
			data-testid="traffic-journeys-table">
			<thead>
				<tr>
					<th scope="col">{{ t('portaliq', 'From') }}</th>
					<th scope="col">{{ t('portaliq', 'To') }}</th>
					<th scope="col" class="traffic-table__number">
						{{ t('portaliq', 'Times') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="row in summary.transitions"
					:key="row.from + ' ' + row.to">
					<td class="traffic-table__path">{{ row.from }}</td>
					<td class="traffic-table__path">{{ row.to }}</td>
					<td class="traffic-table__number">{{ row.count }}</td>
				</tr>
				<tr v-if="summary.transitions.length === 0">
					<td colspan="3" class="traffic-table__muted">
						{{ t('portaliq', 'No visit moved between two pages yet.') }}
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import TrafficEmptyState from './TrafficEmptyState.vue'
import trafficWidgetMixin from './trafficWidgetMixin.js'

export default {
	name: 'TrafficJourneys',

	components: {
		TrafficEmptyState,
	},

	mixins: [trafficWidgetMixin],
}
</script>

<style scoped src="./trafficTable.css"></style>
