<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  TrafficPages — the ten most viewed pages of the chosen range, with how
  often each was where a visit started and where it ended
  (portal-traffic-analytics), and the paths that were requested but do
  not exist (portal-traffic-outcomes).

  @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-session-must-be-reconstructable-into-an-ordered-journey
  @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-missing-pages-must-be-reported-by-the-renderer-and-listed
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

		<!-- Missing pages (portal-traffic-outcomes): the paths the renderer
		     answered with not found. Listed under the pages rather than in
		     a widget of their own because a broken link is a page problem,
		     and the person fixing pages is the one reading this table. -->
		<h3
			v-if="emptyState === '' && summary.notFound.length > 0"
			class="traffic-table__subheading">
			{{ t('portaliq', 'Missing pages') }}
		</h3>
		<table
			v-if="emptyState === '' && summary.notFound.length > 0"
			class="traffic-table__table"
			data-testid="traffic-missing-pages">
			<thead>
				<tr>
					<th scope="col">{{ t('portaliq', 'Requested path') }}</th>
					<th scope="col" class="traffic-table__number">
						{{ t('portaliq', 'Times') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in summary.notFound" :key="row.path">
					<td class="traffic-table__path">{{ row.path }}</td>
					<td class="traffic-table__number">{{ row.hits }}</td>
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
