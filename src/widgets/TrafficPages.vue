<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  TrafficPages — the ten most viewed pages of the last 30 days, with how
  often each was where a visit started and where it ended
  (portal-traffic-analytics).

  @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-session-must-be-reconstructable-into-an-ordered-journey
-->
<template>
	<div class="traffic-table" data-testid="traffic-pages">
		<TrafficEmptyState :state="emptyState" />
		<table
			v-if="emptyState === ''"
			class="traffic-table__table"
			data-testid="traffic-pages-table">
			<thead>
				<tr>
					<th scope="col">{{ t('portaliq', 'Page') }}</th>
					<th scope="col" class="traffic-table__number">
						{{ t('portaliq', 'Views') }}
					</th>
					<th scope="col" class="traffic-table__number">
						{{ t('portaliq', 'Entrances') }}
					</th>
					<th scope="col" class="traffic-table__number">
						{{ t('portaliq', 'Exits') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in summary.pages" :key="row.path">
					<td class="traffic-table__path">{{ row.path }}</td>
					<td class="traffic-table__number">{{ row.views }}</td>
					<td class="traffic-table__number">{{ row.entrances }}</td>
					<td class="traffic-table__number">{{ row.exits }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import TrafficEmptyState from './TrafficEmptyState.vue'
import trafficWidgetMixin from './trafficWidgetMixin.js'

export default {
	name: 'TrafficPages',

	components: {
		TrafficEmptyState,
	},

	mixins: [trafficWidgetMixin],
}
</script>

<style scoped src="./trafficTable.css"></style>
