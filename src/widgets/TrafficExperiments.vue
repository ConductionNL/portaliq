<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  TrafficExperiments — what each page experiment's variants achieved over
  the chosen range (portal-traffic-experiments): per variant the visits,
  the conversions on the experiment's goal and the rate, and the verdict.

  "Not enough data" is a state of its own, shown before any winner: a
  difference between two small numbers is a coin, and a widget that
  crowned one would be read as a result. The verdict is re-derived from
  the summed counts of the range, never averaged over days.

  @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-winner-must-only-be-named-with-enough-sessions-and-a-significant-difference
-->
<template>
	<div class="traffic-table" data-testid="traffic-experiments">
		<TrafficEmptyState :state="emptyState" />

		<div
			v-if="emptyState === '' && experiments.length === 0"
			class="traffic-experiments__none"
			data-testid="traffic-experiments-empty">
			<p>
				{{
					t(
						'portaliq',
						'No experiments defined. An experiment shows two or more versions of a page to different visitors and counts which one meets a goal more often.',
					)
				}}
			</p>
			<router-link
				v-if="portalId !== ''"
				:to="'/portals/' + portalId"
				class="traffic-experiments__link"
				data-testid="traffic-experiments-settings-link">
				{{ t('portaliq', 'Define experiments in the portal settings') }}
			</router-link>
		</div>

		<section
			v-for="experiment in experiments"
			:key="experiment.id"
			class="traffic-experiments__experiment"
			:data-testid="'traffic-experiment-' + experiment.id">
			<h3 class="traffic-table__subheading">
				{{ experiment.name }}
				<span class="traffic-experiments__status">{{
					statusLabel(experiment.status)
				}}</span>
			</h3>
			<p
				class="traffic-experiments__verdict"
				data-testid="traffic-experiment-verdict">
				{{ verdictLabel(experiment) }}
			</p>
			<table class="traffic-table__table">
				<thead>
					<tr>
						<th scope="col">{{ t('portaliq', 'Variant') }}</th>
						<th scope="col" class="traffic-table__number">
							{{ t('portaliq', 'Visits') }}
						</th>
						<th scope="col" class="traffic-table__number">
							{{ t('portaliq', 'Conversions') }}
						</th>
						<th scope="col" class="traffic-table__number">
							{{ t('portaliq', 'Rate') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="variant in experiment.variants"
						:key="variant.id"
						:data-testid="'traffic-variant-' + variant.id">
						<td>
							{{ variant.name }}
							<span
								v-if="experiment.winner === variant.id"
								class="traffic-experiments__winner"
								data-testid="traffic-variant-winner">
								{{ t('portaliq', 'winner') }}
							</span>
						</td>
						<td
							class="traffic-table__number"
							data-testid="traffic-variant-sessions">
							{{ variant.sessions }}
						</td>
						<td class="traffic-table__number">
							{{ variant.conversions }}
						</td>
						<td class="traffic-table__number">
							{{ percent(variant.rate) }}
						</td>
					</tr>
				</tbody>
			</table>
		</section>
	</div>
</template>

<script>
import TrafficEmptyState from './TrafficEmptyState.vue'
import { MIN_EXPERIMENT_SESSIONS } from '../lib/trafficSummary.js'
import trafficWidgetMixin from './trafficWidgetMixin.js'

export default {
	name: 'TrafficExperiments',

	components: {
		TrafficEmptyState,
	},

	mixins: [trafficWidgetMixin],

	computed: {
		/**
		 * The experiments to show: those the range counted, plus any the
		 * portal declares that no day counted yet, so a freshly started
		 * experiment is a row with zeros rather than absent.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-page-experiment-must-be-evaluated-per-session-against-its-goal
		 * @return {Array<object>} The rows.
		 */
		experiments() {
			const counted = this.summary.experiments || []
			const seen = {}
			counted.forEach((e) => {
				seen[e.id] = true
			})
			const declared =
				(this.portal
					&& this.portal.traffic
					&& this.portal.traffic.experiments)
				|| []
			const pending = (Array.isArray(declared) ? declared : [])
				.filter(
					(e) =>
						e
						&& typeof e.id === 'string'
						&& !seen[e.id]
						&& (e.status === 'running' || e.status === 'stopped'),
				)
				.map((e) => ({
					id: e.id,
					name: String(e.name || e.id),
					status: String(e.status),
					variants: (Array.isArray(e.variants) ? e.variants : [])
						.filter((v) => v && typeof v.id === 'string')
						.map((v) => ({
							id: v.id,
							name: String(v.name || v.id),
							sessions: 0,
							conversions: 0,
							rate: 0,
						})),
					winner: '',
					confidence: 0,
					enough: false,
				}))
			return counted.concat(pending)
		},

		/**
		 * The selected portal's object id, for the link to its detail page.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-page-experiment-must-be-evaluated-per-session-against-its-goal
		 * @return {string} The id, or ''.
		 */
		portalId() {
			const portal = this.portal || {}
			const self = portal['@self'] || {}
			return String(self.id || self.uuid || portal.id || '')
		},
	},

	methods: {
		/**
		 * The status as a word.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-page-experiment-must-be-evaluated-per-session-against-its-goal
		 * @param {string} status The status.
		 * @return {string} The label.
		 */
		statusLabel(status) {
			if (status === 'stopped') {
				return this.t('portaliq', 'stopped')
			}
			return this.t('portaliq', 'running')
		},

		/**
		 * The sentence above the table: not enough data, a winner with
		 * its confidence, or no clear difference yet.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-winner-must-only-be-named-with-enough-sessions-and-a-significant-difference
		 * @param {object} experiment The experiment row.
		 * @return {string} The label.
		 */
		verdictLabel(experiment) {
			if (!experiment.enough) {
				return this.t(
					'portaliq',
					'Not enough data: every variant needs at least {min} visits before a winner can be named.',
					{ min: MIN_EXPERIMENT_SESSIONS },
				)
			}
			if (experiment.winner !== '') {
				const winner = experiment.variants.find(
					(v) => v.id === experiment.winner,
				)
				return this.t(
					'portaliq',
					'{name} wins with {confidence}% confidence.',
					{
						name: winner ? winner.name : experiment.winner,
						confidence: Math.round(experiment.confidence * 1000) / 10,
					},
				)
			}
			return this.t(
				'portaliq',
				'No clear difference yet ({confidence}% confidence; 95% is needed).',
				{ confidence: Math.round(experiment.confidence * 1000) / 10 },
			)
		},

		/**
		 * A rate as a percentage with one decimal.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-page-experiment-must-be-evaluated-per-session-against-its-goal
		 * @param {number} rate The rate, 0 to 1.
		 * @return {string} The percentage.
		 */
		percent(rate) {
			return Math.round((Number(rate) || 0) * 1000) / 10 + '%'
		},
	},
}
</script>

<style scoped src="./trafficTable.css"></style>

<style scoped>
.traffic-experiments__none {
	padding: 8px 4px;
	color: var(--color-text-maxcontrast);
}

.traffic-experiments__link {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.traffic-experiments__experiment {
	margin-bottom: 12px;
}

.traffic-experiments__status {
	margin-inline-start: 8px;
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

.traffic-experiments__verdict {
	padding: 0 8px 8px;
	color: var(--color-text-maxcontrast);
}

.traffic-experiments__winner {
	margin-inline-start: 8px;
	padding: 1px 6px;
	border-radius: var(--border-radius-pill);
	background: var(--color-success);
	color: var(--color-primary-element-text);
	font-size: 12px;
}
</style>
