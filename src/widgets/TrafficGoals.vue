<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  TrafficGoals — what the portal's goals achieved over the chosen range
  (portal-traffic-outcomes): per goal the conversions (visits that met
  it), the completions (events), the rate over all visits and the value.

  A portal that declared no goals is told so, with a link to where they
  are declared, rather than shown an empty table: an empty table reads
  as "nothing converted", which is a different and worse message.

  @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-goals-must-be-evaluated-from-the-portals-own-definitions
-->
<template>
	<div class="traffic-table" data-testid="traffic-goals">
		<TrafficEmptyState :state="emptyState" />

		<div
			v-if="emptyState === '' && !hasGoals"
			class="traffic-goals__none"
			data-testid="traffic-goals-empty">
			<p>
				{{
					t(
						'portaliq',
						'No goals defined. A goal names an outcome you count as a success: a page reached, a download, a form submitted or a search.',
					)
				}}
			</p>
			<router-link
				v-if="portalId !== ''"
				:to="'/portals/' + portalId"
				class="traffic-goals__link"
				data-testid="traffic-goals-settings-link">
				{{ t('portaliq', 'Define goals in the portal settings') }}
			</router-link>
		</div>

		<div v-if="emptyState === '' && hasGoals" class="traffic-goals__body">
			<p class="traffic-goals__rate" data-testid="traffic-goals-rate">
				{{ rateLabel }}
			</p>
			<table class="traffic-table__table" data-testid="traffic-goals-table">
				<thead>
					<tr>
						<th scope="col">{{ t('portaliq', 'Goal') }}</th>
						<th scope="col" class="traffic-table__number">
							{{ t('portaliq', 'Conversions') }}
						</th>
						<th scope="col" class="traffic-table__number">
							{{ t('portaliq', 'Completions') }}
						</th>
						<th scope="col" class="traffic-table__number">
							{{ t('portaliq', 'Rate') }}
						</th>
						<th scope="col" class="traffic-table__number">
							{{ t('portaliq', 'Value') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="row in summary.goals"
						:key="row.id"
						:data-testid="'traffic-goal-' + row.id">
						<td>{{ row.name }}</td>
						<td
							class="traffic-table__number"
							data-testid="traffic-goal-conversions">
							{{ row.conversions }}
						</td>
						<td class="traffic-table__number">{{ row.completions }}</td>
						<td class="traffic-table__number">
							{{ percent(row.conversions) }}
						</td>
						<td class="traffic-table__number">{{ row.value }}</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import TrafficEmptyState from './TrafficEmptyState.vue'
import trafficWidgetMixin from './trafficWidgetMixin.js'

export default {
	name: 'TrafficGoals',

	components: {
		TrafficEmptyState,
	},

	mixins: [trafficWidgetMixin],

	computed: {
		/**
		 * Whether the selected portal declared any goal, read from the
		 * portal record so a goal with no conversions yet is still a row.
		 *
		 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-goals-must-be-evaluated-from-the-portals-own-definitions
		 * @return {boolean} True when there is a goal to show.
		 */
		hasGoals() {
			const declared =
				this.portal && this.portal.traffic && this.portal.traffic.goals
			return (
				(Array.isArray(declared) && declared.length > 0)
				|| this.summary.goals.length > 0
			)
		},

		/**
		 * The selected portal's object id, for the link to its detail page.
		 *
		 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-goals-must-be-evaluated-from-the-portals-own-definitions
		 * @return {string} The id, or ''.
		 */
		portalId() {
			const portal = this.portal || {}
			const self = portal['@self'] || {}
			return String(self.id || self.uuid || portal.id || '')
		},

		/**
		 * The sentence above the table: the share of visits that met any
		 * goal.
		 *
		 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-goals-must-be-evaluated-from-the-portals-own-definitions
		 * @return {string} The label.
		 */
		rateLabel() {
			return t('portaliq', '{rate}% of visits met a goal {range}', {
				rate: Math.round(this.summary.conversionRate * 1000) / 10,
				range: this.rangeLabel,
			})
		},
	},

	methods: {
		/**
		 * Conversions as a share of the range's sessions.
		 *
		 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-goals-must-be-evaluated-from-the-portals-own-definitions
		 * @param {number} conversions The goal's conversions.
		 * @return {string} A percentage with one decimal.
		 */
		percent(conversions) {
			const sessions = this.summary.totals.sessions
			if (sessions <= 0) {
				return '0%'
			}
			return Math.round((conversions / sessions) * 1000) / 10 + '%'
		},
	},
}
</script>

<style scoped src="./trafficTable.css"></style>

<style scoped>
.traffic-goals__none {
	padding: 8px 4px;
	color: var(--color-text-maxcontrast);
}

.traffic-goals__link {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.traffic-goals__rate {
	padding: 4px 8px 8px;
	color: var(--color-text-maxcontrast);
}
</style>
