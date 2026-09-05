<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  TrafficErrors — the script errors visitors' browsers reported in the
  chosen range (portal-traffic-reporting): the message, the file, how
  often, and the pages it happened on. Never a stack trace; the client
  never sends one.

  @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-script-errors-must-be-reported-without-the-stack-or-the-query-string
-->
<template>
	<div class="traffic-table" data-testid="traffic-errors">
		<TrafficEmptyState :state="emptyState" />
		<p
			v-if="emptyState === '' && !enabled"
			class="traffic-table__muted"
			data-testid="traffic-errors-not-measured">
			{{
				t(
					'portaliq',
					'Script errors are not measured for this portal. Enable the js_error event in the portal settings.',
				)
			}}
		</p>
		<table
			v-else-if="emptyState === ''"
			class="traffic-table__table"
			data-testid="traffic-errors-table">
			<thead>
				<tr>
					<th scope="col">{{ t('portaliq', 'Error') }}</th>
					<th scope="col">{{ t('portaliq', 'Script') }}</th>
					<th scope="col">{{ t('portaliq', 'Pages') }}</th>
					<th scope="col" class="traffic-table__number">
						{{ t('portaliq', 'Times') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="row in summary.errors"
					:key="row.message + row.source"
					data-testid="traffic-error-row">
					<td class="traffic-table__path">{{ row.message }}</td>
					<td class="traffic-table__path">{{ row.source }}</td>
					<td class="traffic-table__path">{{ row.pages.join(', ') }}</td>
					<td class="traffic-table__number">{{ row.hits }}</td>
				</tr>
				<tr v-if="summary.errors.length === 0">
					<td colspan="4" class="traffic-table__muted">
						{{ t('portaliq', 'No script errors in this period.') }}
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
	name: 'TrafficErrors',

	components: {
		TrafficEmptyState,
	},

	mixins: [trafficWidgetMixin],

	computed: {
		/**
		 * Whether the portal enabled the js_error event at all. A portal
		 * that did not is told so rather than shown an empty list that
		 * reads as "no errors".
		 *
		 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-script-errors-must-be-reported-without-the-stack-or-the-query-string
		 * @return {boolean} True when enabled.
		 */
		enabled() {
			const events =
				this.portal && this.portal.traffic && this.portal.traffic.events
			return Array.isArray(events) && events.includes('js_error')
		},
	},
}
</script>

<style scoped src="./trafficTable.css"></style>
