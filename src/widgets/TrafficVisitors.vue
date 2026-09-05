<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  TrafficVisitors — who visited, over the chosen range
  (portal-traffic-visitors-and-geo).

  Visitors, new versus returning where the portal can tell, the distinct
  accounts where it links them, and the five breakdowns: device type,
  browser, operating system, language and region. Two honesties are built
  in: a cookieless portal reads "not available" for new and returning
  rather than a zero, and a dimension the portal never enabled reads "not
  measured" rather than an empty list.

  @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-visitors-widget-must-show-who-visited-without-inventing-a-number
-->
<template>
	<div class="traffic-visitors" data-testid="traffic-visitors">
		<TrafficEmptyState :state="emptyState" />

		<div v-if="emptyState === ''" class="traffic-visitors__body">
			<div class="traffic-visitors__tiles">
				<CnStatsBlock
					:title="t('portaliq', 'Visitors')"
					:count="summary.visitors.visitors"
					:countLabel="rangeLabel"
					:loading="loading"
					variant="primary"
					data-testid="traffic-tile-visitors-total" />
				<CnStatsBlock
					v-if="summary.visitors.newReturningAvailable"
					:title="t('portaliq', 'New visitors')"
					:count="summary.visitors.newVisitors"
					:countLabel="rangeLabel"
					:loading="loading"
					data-testid="traffic-tile-new-visitors" />
				<CnStatsBlock
					v-if="summary.visitors.newReturningAvailable"
					:title="t('portaliq', 'Returning visitors')"
					:count="summary.visitors.returningVisitors"
					:countLabel="rangeLabel"
					:loading="loading"
					data-testid="traffic-tile-returning-visitors" />
				<CnStatsBlock
					v-if="summary.visitors.accountsAvailable"
					:title="t('portaliq', 'Signed-in accounts')"
					:count="summary.visitors.accounts"
					:countLabel="rangeLabel"
					:loading="loading"
					variant="success"
					data-testid="traffic-tile-accounts" />
			</div>

			<p
				v-if="!summary.visitors.newReturningAvailable"
				class="traffic-visitors__note"
				data-testid="traffic-visitors-cookieless">
				{{
					t(
						'portaliq',
						'New versus returning is not available in cookieless mode. A visitor is recognised for one day only; switch on a persisted client id in the portal settings to count return visits.',
					)
				}}
			</p>

			<div class="traffic-visitors__lists">
				<section
					v-for="list in lists"
					:key="list.key"
					class="traffic-visitors__list"
					:data-testid="'traffic-breakdown-' + list.key">
					<h3 class="traffic-visitors__heading">{{ list.title }}</h3>
					<p v-if="!list.measured" class="traffic-visitors__muted">
						{{ t('portaliq', 'Not measured for this portal.') }}
					</p>
					<p
						v-else-if="list.rows.length === 0"
						class="traffic-visitors__muted">
						{{ t('portaliq', 'Nothing recorded in this period.') }}
					</p>
					<table v-else class="traffic-visitors__table">
						<thead class="traffic-visitors__head">
							<tr>
								<th scope="col">{{ list.title }}</th>
								<th scope="col" class="traffic-visitors__number">
									{{ t('portaliq', 'Sessions') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in list.rows" :key="row.value">
								<td class="traffic-visitors__value">
									{{ row.value }}
								</td>
								<td class="traffic-visitors__number">
									{{ row.count }}
								</td>
							</tr>
						</tbody>
					</table>
				</section>
			</div>
		</div>
	</div>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import TrafficEmptyState from './TrafficEmptyState.vue'
import { BREAKDOWNS, hasDimension } from '../lib/trafficSummary.js'
import trafficWidgetMixin from './trafficWidgetMixin.js'

export default {
	name: 'TrafficVisitors',

	components: {
		CnStatsBlock,
		TrafficEmptyState,
	},

	mixins: [trafficWidgetMixin],

	computed: {
		/**
		 * The five breakdowns with their heading, whether the portal
		 * measures them, and the ranked rows.
		 *
		 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-visitors-widget-must-show-who-visited-without-inventing-a-number
		 * @return {Array<{key: string, title: string, measured: boolean, rows: Array<object>}>} The lists.
		 */
		lists() {
			const titles = {
				deviceType: this.t('portaliq', 'Devices'),
				browser: this.t('portaliq', 'Browsers'),
				os: this.t('portaliq', 'Operating systems'),
				language: this.t('portaliq', 'Languages'),
				region: this.t('portaliq', 'Regions'),
			}
			return BREAKDOWNS.map((b) => ({
				key: b.key,
				title: titles[b.key] || b.key,
				measured: hasDimension(this.portal, b.key),
				rows: this.summary.breakdowns[b.key] || [],
			}))
		},
	},
}
</script>

<style scoped>
.traffic-visitors {
	padding: 4px;
	overflow: auto;
}

.traffic-visitors__body {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.traffic-visitors__tiles {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
	gap: 12px;
}

.traffic-visitors__note {
	margin: 0;
	color: var(--color-text-maxcontrast);
	line-height: 1.5;
}

.traffic-visitors__lists {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 16px;
}

.traffic-visitors__heading {
	margin: 0 0 4px;
	font-size: 14px;
	font-weight: bold;
}

.traffic-visitors__muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.traffic-visitors__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
}

.traffic-visitors__table td,
.traffic-visitors__table th {
	padding: 4px 6px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

/* The heading above the table already names the dimension; the header
   row stays for assistive technology and is hidden visually. */
.traffic-visitors__head {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}

.traffic-visitors__value {
	word-break: break-all;
}

.traffic-visitors__number {
	text-align: end;
	white-space: nowrap;
}
</style>
