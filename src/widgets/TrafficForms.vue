<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  TrafficForms — what happened to each form over the chosen range
  (portal-traffic-outcomes): starts, submits, abandons, the completion
  rate and the field most people left on. Ids and times only; no value
  is ever collected, so none can be shown.

  @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-form-analytics-must-never-carry-a-value
-->
<template>
	<div class="traffic-table" data-testid="traffic-forms">
		<TrafficEmptyState :state="emptyState" />
		<table
			v-if="emptyState === ''"
			class="traffic-table__table"
			data-testid="traffic-forms-table">
			<thead>
				<tr>
					<th scope="col">{{ t('portaliq', 'Form') }}</th>
					<th scope="col" class="traffic-table__number">
						{{ t('portaliq', 'Started') }}
					</th>
					<th scope="col" class="traffic-table__number">
						{{ t('portaliq', 'Submitted') }}
					</th>
					<th scope="col" class="traffic-table__number">
						{{ t('portaliq', 'Abandoned') }}
					</th>
					<th scope="col" class="traffic-table__number">
						{{ t('portaliq', 'Completion') }}
					</th>
					<th scope="col">{{ t('portaliq', 'Most left on') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="row in summary.forms"
					:key="row.formId"
					:data-testid="'traffic-form-' + row.formId">
					<td class="traffic-table__path">{{ row.formId }}</td>
					<td class="traffic-table__number">{{ row.starts }}</td>
					<td class="traffic-table__number">{{ row.submits }}</td>
					<td
						class="traffic-table__number"
						data-testid="traffic-form-abandons">
						{{ row.abandons }}
					</td>
					<td class="traffic-table__number">
						{{ Math.round(row.completionRate * 100) }}%
					</td>
					<td class="traffic-table__path">
						{{ row.leaveField || t('portaliq', 'Nobody left') }}
					</td>
				</tr>
				<tr v-if="summary.forms.length === 0">
					<td colspan="6" class="traffic-table__muted">
						{{
							t(
								'portaliq',
								'No form activity yet. Enable the form events for this portal to count starts, fields and abandons; values are never collected.',
							)
						}}
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
	name: 'TrafficForms',

	components: {
		TrafficEmptyState,
	},

	mixins: [trafficWidgetMixin],
}
</script>

<style scoped src="./trafficTable.css"></style>
