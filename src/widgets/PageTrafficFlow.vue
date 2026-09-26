<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  PageTrafficFlow: where a portal page's visitors came from, or where
  they went next (portal-page-traffic). `content.direction` picks which.

  Incoming: the pages within the portal visitors came from, the sessions
  that entered the portal on this page, and the sites and channels that
  brought them. Outgoing: the next pages, the sessions that left the
  portal here, and the outbound links clicked here.

  It reads GET /api/traffic/page, the same answer the page's KPI cards
  read. "Not measured" for a portal with measurement off, and "Not
  available for this period" for a list no day of the period counted,
  never an empty table that reads as nobody.

  @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-show-where-visitors-came-from-and-went-next
-->
<template>
	<div class="traffic-table" :data-testid="testId">
		<div class="page-traffic-flow__period">
			<label :for="selectId">{{ t('portaliq', 'Period') }}</label>
			<select :id="selectId" v-model="days" :data-testid="testId + '-period'">
				<option
					v-for="period in periods"
					:key="period.id"
					:value="period.id">
					{{ period.label }}
				</option>
			</select>
		</div>

		<NcLoadingIcon v-if="loading" :size="22" />
		<p
			v-else-if="state !== ''"
			class="traffic-table__muted"
			:data-testid="testId + '-state'">
			{{ stateText }}
		</p>
		<template v-else>
			<p
				class="page-traffic-flow__boundary"
				:data-testid="testId + '-boundary'">
				{{ boundaryLabel }}: <strong>{{ flow.boundary }}</strong>
			</p>

			<table class="traffic-table__table" :data-testid="testId + '-pages'">
				<thead>
					<tr>
						<th scope="col">{{ pagesHeading }}</th>
						<th scope="col" class="traffic-table__number">
							{{ t('portaliq', 'Times') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in flow.pages" :key="row.path">
						<td class="traffic-table__path">{{ row.path }}</td>
						<td class="traffic-table__number">{{ row.count }}</td>
					</tr>
					<tr v-if="flow.pages.length === 0">
						<td colspan="2" class="traffic-table__muted">
							{{ noPagesText }}
						</td>
					</tr>
				</tbody>
			</table>

			<h3 class="traffic-table__subheading">{{ sourcesHeading }}</h3>
			<p
				v-if="flow.sources === null"
				class="traffic-table__muted"
				:data-testid="testId + '-sources-unavailable'">
				{{ t('portaliq', 'Not available for this period') }}
			</p>
			<table
				v-else-if="incoming"
				class="traffic-table__table"
				:data-testid="testId + '-sources'">
				<thead>
					<tr>
						<th scope="col">{{ t('portaliq', 'Referring site') }}</th>
						<th scope="col">{{ t('portaliq', 'Channel') }}</th>
						<th scope="col" class="traffic-table__number">
							{{ t('portaliq', 'Sessions') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="row in flow.sources"
						:key="row.host + ' ' + row.channel">
						<td class="traffic-table__path">
							{{ row.host || t('portaliq', 'No referring site') }}
						</td>
						<td>{{ row.channel }}</td>
						<td class="traffic-table__number">{{ row.count }}</td>
					</tr>
					<tr v-if="flow.sources.length === 0">
						<td colspan="3" class="traffic-table__muted">
							{{ t('portaliq', 'No referring site recorded yet.') }}
						</td>
					</tr>
				</tbody>
			</table>
			<table
				v-else
				class="traffic-table__table"
				:data-testid="testId + '-sources'">
				<thead>
					<tr>
						<th scope="col">{{ t('portaliq', 'Outbound link') }}</th>
						<th scope="col" class="traffic-table__number">
							{{ t('portaliq', 'Clicks') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in flow.sources" :key="row.url">
						<td class="traffic-table__path">{{ row.url }}</td>
						<td class="traffic-table__number">{{ row.count }}</td>
					</tr>
					<tr v-if="flow.sources.length === 0">
						<td colspan="2" class="traffic-table__muted">
							{{ t('portaliq', 'No outbound link clicked yet.') }}
						</td>
					</tr>
				</tbody>
			</table>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon } from '@nextcloud/vue'
import { flowOf, pageRoute } from '../lib/pageTraffic.js'

export default {
	name: 'PageTrafficFlow',

	components: {
		NcLoadingIcon,
	},

	props: {
		/**
		 * The widget's manifest content: `direction` (`incoming` or
		 * `outgoing`) and an optional `testId`.
		 */
		content: {
			type: Object,
			default: () => ({}),
		},

		/** The page the detail page shows. */
		objectData: {
			type: Object,
			default: null,
		},
	},

	/**
	 * The chosen period, the answer and the request state.
	 *
	 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-show-where-visitors-came-from-and-went-next
	 * @return {object} The state.
	 */
	data() {
		return {
			// '' is the default period, 30 days, as on the KPI cards.
			days: '',
			answer: null,
			loading: false,
			failed: false,
		}
	},

	computed: {
		/**
		 * Whether this widget shows where visitors came from.
		 *
		 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-show-where-visitors-came-from-and-went-next
		 * @return {boolean} True for incoming.
		 */
		incoming() {
			return this.content.direction !== 'outgoing'
		},

		/**
		 * The widget's test id.
		 *
		 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-show-where-visitors-came-from-and-went-next
		 * @return {string} The test id.
		 */
		testId() {
			return (
				this.content.testId
				|| (this.incoming
					? 'page-traffic-incoming'
					: 'page-traffic-outgoing')
			)
		},

		/**
		 * The period select's element id, unique per widget.
		 *
		 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-show-where-visitors-came-from-and-went-next
		 * @return {string} The id.
		 */
		selectId() {
			return this.testId + '-period-select'
		},

		/**
		 * The periods on offer, labelled like the KPI cards' picker.
		 *
		 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-show-where-visitors-came-from-and-went-next
		 * @return {Array<{id: string, label: string}>} The periods.
		 */
		periods() {
			return [
				{ id: '7', label: this.t('portaliq', 'Last 7 days') },
				{ id: '', label: this.t('portaliq', 'Last 30 days') },
				{ id: '90', label: this.t('portaliq', 'Last 90 days') },
				{ id: '365', label: this.t('portaliq', 'Last 365 days') },
			]
		},

		/**
		 * The page's portal and route, or null when it has neither.
		 *
		 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-show-where-visitors-came-from-and-went-next
		 * @return {{portal: string, route: string}|null} The query.
		 */
		query() {
			const portal = String((this.objectData && this.objectData.portal) || '')
			const route = pageRoute(this.objectData)
			if (portal === '' || route === '') {
				return null
			}
			return { portal, route }
		},

		/**
		 * Why no lists are shown: `unmeasured`, `failed`, or '' to show them.
		 *
		 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-show-where-visitors-came-from-and-went-next
		 * @return {string} The state.
		 */
		state() {
			if (this.failed) {
				return 'failed'
			}
			if (
				this.query === null
				|| (this.answer && this.answer.measured === false)
			) {
				return 'unmeasured'
			}
			if (this.answer === null) {
				return 'failed'
			}
			return ''
		},

		/**
		 * The sentence for a state.
		 *
		 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-show-where-visitors-came-from-and-went-next
		 * @return {string} The text.
		 */
		stateText() {
			if (this.state === 'unmeasured') {
				return this.t('portaliq', 'Not measured')
			}
			return this.t('portaliq', 'Could not load the traffic of this page.')
		},

		/**
		 * This direction's lists.
		 *
		 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-show-where-visitors-came-from-and-went-next
		 * @return {{pages: Array<object>, boundary: number, sources: Array<object>|null}} The lists.
		 */
		flow() {
			const flow = flowOf(this.answer, this.incoming ? 'incoming' : 'outgoing')
			return {
				pages: flow.pages || [],
				boundary: flow.boundary || 0,
				sources: flow.sources,
			}
		},

		/**
		 * The label of the entrances or exits count.
		 *
		 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-show-where-visitors-came-from-and-went-next
		 * @return {string} The label.
		 */
		boundaryLabel() {
			return this.incoming
				? this.t('portaliq', 'Sessions that entered the portal here')
				: this.t('portaliq', 'Sessions that left the portal here')
		},

		/**
		 * The heading of the pages column.
		 *
		 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-show-where-visitors-came-from-and-went-next
		 * @return {string} The heading.
		 */
		pagesHeading() {
			return this.incoming
				? this.t('portaliq', 'Previous page')
				: this.t('portaliq', 'Next page')
		},

		/**
		 * The row shown when no visitor moved between this page and another.
		 *
		 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-show-where-visitors-came-from-and-went-next
		 * @return {string} The text.
		 */
		noPagesText() {
			return this.incoming
				? this.t('portaliq', 'No visitor came here from another page yet.')
				: this.t('portaliq', 'No visitor went on to another page yet.')
		},

		/**
		 * The heading of the sources list.
		 *
		 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-show-where-visitors-came-from-and-went-next
		 * @return {string} The heading.
		 */
		sourcesHeading() {
			return this.incoming
				? this.t('portaliq', 'Referring sites')
				: this.t('portaliq', 'Outbound links')
		},
	},

	watch: {
		/**
		 * A new period asks again.
		 *
		 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-show-where-visitors-came-from-and-went-next
		 * @return {void}
		 */
		days() {
			this.load()
		},

		/**
		 * Another page, or the same page with a new route, asks again.
		 *
		 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-show-where-visitors-came-from-and-went-next
		 * @return {void}
		 */
		query() {
			this.load()
		},
	},

	/**
	 * Ask for the page's traffic once the widget is on screen.
	 *
	 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-show-where-visitors-came-from-and-went-next
	 * @return {void}
	 */
	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Fetch the page endpoint's answer for the chosen period. A page
		 * without a portal or a route asks nothing and reads "Not measured".
		 *
		 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-show-where-visitors-came-from-and-went-next
		 * @return {Promise<void>} Resolves when the answer is in.
		 */
		async load() {
			if (this.query === null) {
				this.answer = null
				return
			}
			this.loading = true
			this.failed = false
			try {
				const { data } = await axios.get(
					generateUrl('/apps/portaliq/api/traffic/page'),
					{
						params: { ...this.query, days: this.days },
					},
				)
				this.answer = data
			} catch {
				this.answer = null
				this.failed = true
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped src="./trafficTable.css"></style>

<style scoped>
.page-traffic-flow__period {
	display: flex;
	gap: 8px;
	align-items: center;
	margin-bottom: 8px;
}

.page-traffic-flow__boundary {
	margin: 0 0 8px;
}
</style>
