<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  TrafficSources — where sessions came from, grouped by channel (organic
  search, referral, social, email, direct), with the busiest referring
  hosts per channel, over the last 30 days (portal-traffic-analytics).

  Below it, the searched terms (portal-traffic-outcomes).

  @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-derived-dimensions-must-be-computed-on-the-server-and-never-accepted-from-the-client
  @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-site-search-must-be-reported-by-the-built-in-search-box
-->
<template>
	<div class="traffic-table" data-testid="traffic-sources">
		<TrafficEmptyState :state="emptyState" />
		<table
			v-if="emptyState === ''"
			class="traffic-table__table"
			data-testid="traffic-sources-table">
			<thead>
				<tr>
					<th scope="col">{{ t('portaliq', 'Channel') }}</th>
					<th scope="col">{{ t('portaliq', 'Referring sites') }}</th>
					<th scope="col" class="traffic-table__number">
						{{ t('portaliq', 'Sessions') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in summary.sources" :key="row.channel">
					<td>{{ row.channel }}</td>
					<td class="traffic-table__path">
						{{ row.hosts.join(', ') || '' }}
					</td>
					<td class="traffic-table__number">{{ row.count }}</td>
				</tr>
				<tr v-if="summary.sources.length === 0">
					<td colspan="3" class="traffic-table__muted">
						{{ t('portaliq', 'No referrer recorded yet.') }}
					</td>
				</tr>
			</tbody>
		</table>

		<!-- Searches (portal-traffic-outcomes): what visitors typed into the
		     site's own search, from the URL and from the built-in search
		     block alike. Only present when the portal keeps search terms. -->
		<h3
			v-if="emptyState === '' && summary.searches.length > 0"
			class="traffic-table__subheading">
			{{ t('portaliq', 'Searches') }}
		</h3>
		<table
			v-if="emptyState === '' && summary.searches.length > 0"
			class="traffic-table__table"
			data-testid="traffic-searches">
			<thead>
				<tr>
					<th scope="col">{{ t('portaliq', 'Search term') }}</th>
					<th scope="col" class="traffic-table__number">
						{{ t('portaliq', 'Times') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in summary.searches" :key="row.term">
					<td class="traffic-table__path">{{ row.term }}</td>
					<td class="traffic-table__number">{{ row.count }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import TrafficEmptyState from './TrafficEmptyState.vue'
import trafficWidgetMixin from './trafficWidgetMixin.js'

export default {
	name: 'TrafficSources',

	components: {
		TrafficEmptyState,
	},

	mixins: [trafficWidgetMixin],
}
</script>

<style scoped src="./trafficTable.css"></style>
