<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  TrafficDimensions — the portal's custom dimensions over the chosen
  range (portal-traffic-outcomes): per declared dimension, its values
  ranked by visits or events, depending on its scope.

  A declared dimension with nothing recorded is still listed, so an
  operator can tell "declared but the page never set it" from "not
  declared".

  @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-custom-dimensions-must-be-declared-before-they-are-stored
-->
<template>
	<div class="traffic-dimensions" data-testid="traffic-dimensions">
		<TrafficEmptyState :state="emptyState" />

		<p
			v-if="emptyState === '' && lists.length === 0"
			class="traffic-dimensions__none"
			data-testid="traffic-dimensions-empty">
			{{
				t(
					'portaliq',
					'No custom dimensions declared. Declare one in the portal settings and set it from the page with window.portaliqTraffic.dimension(id, value).',
				)
			}}
		</p>

		<div v-else-if="emptyState === ''" class="traffic-dimensions__lists">
			<section
				v-for="list in lists"
				:key="list.id"
				class="traffic-dimensions__list"
				:data-testid="'traffic-dimension-' + list.id">
				<h3 class="traffic-dimensions__heading">{{ list.name }}</h3>
				<p v-if="list.rows.length === 0" class="traffic-dimensions__muted">
					{{ t('portaliq', 'Nothing recorded in this period.') }}
				</p>
				<table v-else class="traffic-dimensions__table">
					<thead>
						<tr>
							<th scope="col">{{ t('portaliq', 'Value') }}</th>
							<th scope="col" class="traffic-dimensions__number">
								{{
									list.scope === 'session'
										? t('portaliq', 'Visits')
										: t('portaliq', 'Events')
								}}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in list.rows" :key="row.value">
							<td class="traffic-dimensions__value">
								{{ row.value }}
							</td>
							<td class="traffic-dimensions__number">
								{{ row.count }}
							</td>
						</tr>
					</tbody>
				</table>
			</section>
		</div>
	</div>
</template>

<script>
import TrafficEmptyState from './TrafficEmptyState.vue'
import trafficWidgetMixin from './trafficWidgetMixin.js'

export default {
	name: 'TrafficDimensions',

	components: {
		TrafficEmptyState,
	},

	mixins: [trafficWidgetMixin],

	computed: {
		/**
		 * One list per dimension: the declared ones first, in the portal's
		 * order, then any the rollups carry that the portal since dropped.
		 *
		 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-custom-dimensions-must-be-declared-before-they-are-stored
		 * @return {Array<{id: string, name: string, scope: string, rows: Array<object>}>} The lists.
		 */
		lists() {
			const declared =
				(this.portal
					&& this.portal.traffic
					&& this.portal.traffic.customDimensions)
				|| []
			const counted = this.summary.customDimensions || {}
			const out = []
			const seen = {}
			;(Array.isArray(declared) ? declared : []).forEach((dimension) => {
				const id = String((dimension && dimension.id) || '')
				if (id === '' || seen[id]) {
					return
				}
				seen[id] = true
				out.push({
					id,
					name: String(dimension.name || id),
					scope: dimension.scope === 'session' ? 'session' : 'event',
					rows: counted[id] || [],
				})
			})
			Object.keys(counted).forEach((id) => {
				if (!seen[id]) {
					out.push({ id, name: id, scope: 'event', rows: counted[id] })
				}
			})
			return out
		},
	},
}
</script>

<style scoped>
.traffic-dimensions {
	padding: 4px;
}

.traffic-dimensions__none,
.traffic-dimensions__muted {
	padding: 8px 4px;
	color: var(--color-text-maxcontrast);
}

.traffic-dimensions__lists {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 12px;
}

.traffic-dimensions__heading {
	margin: 4px;
	font-size: 14px;
	font-weight: bold;
}

.traffic-dimensions__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
}

.traffic-dimensions__table th,
.traffic-dimensions__table td {
	padding: 4px 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.traffic-dimensions__table th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.traffic-dimensions__number {
	text-align: end;
	white-space: nowrap;
}

.traffic-dimensions__value {
	word-break: break-all;
}
</style>
