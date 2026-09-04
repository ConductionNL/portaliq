<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  TrafficOverview — the portal selector and the four headline numbers for
  the last 30 days (portal-traffic-analytics).

  The selector lives here and drives every other Traffic widget through
  the shared report store. The warning card lists which sensitive
  switches the selected portal has on, because a portal that persists a
  client id or links accounts is one whose report a privacy officer wants
  flagged, not footnoted.

  @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-traffic-page-must-show-what-was-measured-and-say-when-it-was-not
-->
<template>
	<div class="traffic-overview" data-testid="traffic-overview">
		<div class="traffic-overview__toolbar">
			<NcSelect
				:modelValue="selected"
				:inputLabel="t('portaliq', 'Portal')"
				:options="options"
				:clearable="false"
				:disabled="report.loadingPortals"
				label="label"
				data-testid="traffic-portal-select"
				@update:modelValue="onSelect" />
		</div>

		<NcNoteCard v-if="report.error" type="error" data-testid="traffic-error">
			{{ report.error }}
		</NcNoteCard>

		<NcNoteCard
			v-if="warned.length > 0"
			type="warning"
			data-testid="traffic-sensitive-warning">
			<p>
				{{
					t(
						'portaliq',
						'This portal has sensitive measurement switched on. Each of these changes what the portal knows about a person, not how much traffic it counts.',
					)
				}}
			</p>
			<ul class="traffic-overview__switches">
				<li v-for="key in warned" :key="key">
					{{ switchLabel(key) }}
				</li>
			</ul>
		</NcNoteCard>

		<TrafficEmptyState :state="emptyState" />

		<div v-if="emptyState === ''" class="traffic-overview__tiles">
			<CnStatsBlock
				:title="t('portaliq', 'Page views')"
				:count="summary.totals.pageViews"
				:countLabel="t('portaliq', 'in 30 days')"
				:loading="loading"
				variant="primary"
				data-testid="traffic-tile-page-views" />
			<CnStatsBlock
				:title="t('portaliq', 'Sessions')"
				:count="summary.totals.sessions"
				:countLabel="t('portaliq', 'in 30 days')"
				:loading="loading"
				data-testid="traffic-tile-sessions" />
			<CnStatsBlock
				:title="t('portaliq', 'Visitors')"
				:count="summary.totals.visitors"
				:countLabel="t('portaliq', 'in 30 days')"
				:loading="loading"
				data-testid="traffic-tile-visitors" />
			<CnStatsBlock
				:title="t('portaliq', 'Engaged sessions')"
				:count="summary.totals.engagedSessions"
				:countLabel="t('portaliq', 'in 30 days')"
				:loading="loading"
				variant="success"
				data-testid="traffic-tile-engaged" />
		</div>
	</div>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import { NcNoteCard, NcSelect } from '@nextcloud/vue'
import TrafficEmptyState from './TrafficEmptyState.vue'
import { warnedSwitches } from '../lib/trafficSummary.js'
import trafficWidgetMixin from './trafficWidgetMixin.js'

export default {
	name: 'TrafficOverview',

	components: {
		CnStatsBlock,
		NcNoteCard,
		NcSelect,
		TrafficEmptyState,
	},

	mixins: [trafficWidgetMixin],

	computed: {
		/**
		 * The portals as select options, measured ones marked.
		 *
		 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-traffic-page-must-show-what-was-measured-and-say-when-it-was-not
		 * @return {Array<{id: string, label: string}>} The options.
		 */
		options() {
			return this.report.portals.map((portal) => ({
				id: portal.slug,
				label:
					portal.traffic && portal.traffic.enabled === true
						? String(portal.title || portal.slug)
						: this.t('portaliq', '{title} (not measured)', {
								title: String(portal.title || portal.slug),
							}),
			}))
		},

		/**
		 * The selected option, or null.
		 *
		 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-traffic-page-must-show-what-was-measured-and-say-when-it-was-not
		 * @return {object|null} The option.
		 */
		selected() {
			return this.options.find((o) => o.id === this.report.portalSlug) || null
		},

		/**
		 * The sensitive switches the selected portal has on.
		 *
		 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-sensitive-measurement-must-be-off-by-default-and-warned-in-the-admin-ui
		 * @return {Array<string>} Switch names.
		 */
		warned() {
			return warnedSwitches(this.portal)
		},
	},

	mounted() {
		this.report.load()
	},

	methods: {
		/**
		 * Switch the page to another portal.
		 *
		 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-traffic-page-must-show-what-was-measured-and-say-when-it-was-not
		 * @param {object|null} option The chosen option.
		 * @return {void}
		 */
		onSelect(option) {
			if (option && option.id) {
				this.report.select(option.id)
			}
		},

		/**
		 * The human wording of a sensitive switch.
		 *
		 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-sensitive-measurement-must-be-off-by-default-and-warned-in-the-admin-ui
		 * @param {string} key The switch.
		 * @return {string} The label.
		 */
		switchLabel(key) {
			const labels = {
				persistClientId: this.t(
					'portaliq',
					"A client id is stored in the visitor's browser, so return visits are recognised. A consent banner is then likely required.",
				),

				accountLinking: this.t(
					'portaliq',
					'Events are linked to the signed-in portal account, which ties a named person to their browsing.',
				),

				heatmaps: this.t(
					'portaliq',
					'Heatmaps are switched on. Clicks and scrolls are recorded per page.',
				),

				sessionRecording: this.t(
					'portaliq',
					'Session recording is switched on. Whole visits are replayed, including what is typed.',
				),
			}
			return labels[key] || key
		},
	},
}
</script>

<style scoped>
.traffic-overview {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px;
}

.traffic-overview__toolbar {
	max-width: 420px;
}

.traffic-overview__tiles {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 12px;
}

.traffic-overview__switches {
	margin: 8px 0 0 20px;
	list-style: disc;
}
</style>
