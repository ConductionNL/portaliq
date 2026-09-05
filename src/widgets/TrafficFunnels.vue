<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  TrafficFunnels — how far visits walked each funnel over the chosen
  range (portal-traffic-outcomes): one horizontal bar per step, its
  width the share of the first step's visits, with the sessions and the
  drop-off from the step before.

  No chart library: a funnel is five rectangles, and CSS draws them in
  the theme's own colours with the number in text beside each, which is
  what a screen reader gets too.

  @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-funnel-steps-must-count-in-order
-->
<template>
	<div class="traffic-funnels" data-testid="traffic-funnels">
		<TrafficEmptyState :state="emptyState" />

		<p
			v-if="emptyState === '' && summary.funnels.length === 0"
			class="traffic-funnels__none"
			data-testid="traffic-funnels-empty">
			{{
				t(
					'portaliq',
					'No funnels defined. A funnel is an ordered path you expect visitors to walk; define one in the portal settings.',
				)
			}}
		</p>

		<section
			v-for="funnel in summary.funnels"
			:key="funnel.id"
			class="traffic-funnels__funnel"
			:data-testid="'traffic-funnel-' + funnel.id">
			<h3 class="traffic-funnels__heading">{{ funnel.name }}</h3>
			<ol class="traffic-funnels__steps">
				<li
					v-for="(step, index) in funnel.steps"
					:key="index"
					class="traffic-funnels__step"
					data-testid="traffic-funnel-step">
					<span class="traffic-funnels__name">{{ step.name }}</span>
					<span
						class="traffic-funnels__bar"
						role="img"
						:aria-label="barLabel(step, funnel)">
						<span
							class="traffic-funnels__fill"
							:style="{ width: width(step, funnel) }" />
					</span>
					<span
						class="traffic-funnels__count"
						data-testid="traffic-funnel-sessions">
						{{ step.sessions }}
					</span>
					<span class="traffic-funnels__drop">
						{{ index === 0 ? '' : dropLabel(step) }}
					</span>
				</li>
			</ol>
		</section>
	</div>
</template>

<script>
import TrafficEmptyState from './TrafficEmptyState.vue'
import trafficWidgetMixin from './trafficWidgetMixin.js'

export default {
	name: 'TrafficFunnels',

	components: {
		TrafficEmptyState,
	},

	mixins: [trafficWidgetMixin],

	methods: {
		/**
		 * The bar's width: the step's sessions as a share of the first
		 * step's, never below a sliver so a zero step is still a bar.
		 *
		 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-funnel-steps-must-count-in-order
		 * @param {object} step   The step.
		 * @param {object} funnel Its funnel.
		 * @return {string} A CSS percentage.
		 */
		width(step, funnel) {
			const first = funnel.steps.length > 0 ? funnel.steps[0].sessions : 0
			if (first <= 0) {
				return '0%'
			}
			return Math.max(1, Math.round((step.sessions / first) * 100)) + '%'
		},

		/**
		 * The drop-off from the previous step, as text.
		 *
		 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-funnel-steps-must-count-in-order
		 * @param {object} step The step.
		 * @return {string} "-50% drop-off".
		 */
		dropLabel(step) {
			return t('portaliq', '{percent}% dropped off', {
				percent: Math.round(step.dropOff * 1000) / 10,
			})
		},

		/**
		 * What a screen reader hears for the bar.
		 *
		 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-funnel-steps-must-count-in-order
		 * @param {object} step   The step.
		 * @param {object} funnel Its funnel.
		 * @return {string} The label.
		 */
		barLabel(step, funnel) {
			return t(
				'portaliq',
				'{name}: {sessions} visits, {width} of the first step',
				{
					name: step.name,
					sessions: step.sessions,
					width: this.width(step, funnel),
				},
			)
		},
	},
}
</script>

<style scoped>
.traffic-funnels {
	padding: 4px;
}

.traffic-funnels__none {
	padding: 8px 4px;
	color: var(--color-text-maxcontrast);
}

.traffic-funnels__heading {
	margin: 8px 4px 4px;
	font-size: 14px;
	font-weight: bold;
}

.traffic-funnels__steps {
	margin: 0;
	padding: 0;
	list-style: none;
}

.traffic-funnels__step {
	display: grid;
	grid-template-columns: minmax(96px, 1fr) 3fr auto auto;
	gap: 8px;
	align-items: center;
	padding: 4px;
	font-size: 13px;
}

.traffic-funnels__bar {
	display: block;
	height: 18px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	overflow: hidden;
}

.traffic-funnels__fill {
	display: block;
	height: 100%;
	background: var(--color-primary-element);
	border-radius: var(--border-radius);
}

.traffic-funnels__count {
	min-width: 3ch;
	text-align: end;
	font-variant-numeric: tabular-nums;
}

.traffic-funnels__drop {
	min-width: 12ch;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}
</style>
