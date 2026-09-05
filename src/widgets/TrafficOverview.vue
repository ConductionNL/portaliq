<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  TrafficOverview — the portal, period and segment selectors, the Export
  button and the four headline numbers (portal-traffic-analytics,
  portal-traffic-visitors-and-geo, portal-traffic-reporting).

  The selectors live here and drive every other Traffic widget through
  the shared report store. The warning card lists which sensitive
  switches the selected portal has on, because a portal that persists a
  client id or links accounts is one whose report a privacy officer wants
  flagged, not footnoted. A roll-up portal says how many portals it sums,
  so a reader knows the visitors are a sum, not a distinct count.

  @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-traffic-page-must-show-what-was-measured-and-say-when-it-was-not
  @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-segment-must-be-a-saved-filter-over-sessions
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
			<NcSelect
				:modelValue="selectedRange"
				:inputLabel="t('portaliq', 'Period')"
				:options="rangeOptions"
				:clearable="false"
				label="label"
				data-testid="traffic-range-select"
				@update:modelValue="onRange" />
			<NcSelect
				v-if="segmentOptions.length > 1"
				:modelValue="selectedSegment"
				:inputLabel="t('portaliq', 'Segment')"
				:options="segmentOptions"
				:clearable="false"
				label="label"
				data-testid="traffic-segment-select"
				@update:modelValue="onSegment" />
			<NcButton
				v-if="report.exportUrl !== '' && emptyState === ''"
				:href="report.exportUrl"
				download
				data-testid="traffic-export">
				<template #icon>
					<Download :size="20" />
				</template>
				{{ t('portaliq', 'Export') }}
			</NcButton>
			<template v-if="report.rangePreset === 'custom'">
				<NcDateTimePickerNative
					id="traffic-range-from"
					:modelValue="customFrom"
					:label="t('portaliq', 'From')"
					type="date"
					data-testid="traffic-range-from"
					@update:modelValue="onCustom('from', $event)" />
				<NcDateTimePickerNative
					id="traffic-range-to"
					:modelValue="customTo"
					:label="t('portaliq', 'To')"
					type="date"
					data-testid="traffic-range-to"
					@update:modelValue="onCustom('to', $event)" />
			</template>
		</div>

		<NcNoteCard v-if="report.error" type="error" data-testid="traffic-error">
			{{ report.error }}
		</NcNoteCard>

		<NcNoteCard
			v-if="members.length > 0"
			type="info"
			data-testid="traffic-rollup-note">
			{{
				n(
					'portaliq',
					'Roll-up of %n portal: the figures are the sum of its daily records, so a visitor of two portals counts twice.',
					'Roll-up of %n portals: the figures are the sum of their daily records, so a visitor of two portals counts twice.',
					members.length,
				)
			}}
			{{ members.join(', ') }}
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
				:countLabel="rangeLabel"
				:loading="loading"
				variant="primary"
				data-testid="traffic-tile-page-views" />
			<CnStatsBlock
				:title="t('portaliq', 'Sessions')"
				:count="summary.totals.sessions"
				:countLabel="rangeLabel"
				:loading="loading"
				data-testid="traffic-tile-sessions" />
			<CnStatsBlock
				:title="t('portaliq', 'Visitors')"
				:count="summary.totals.visitors"
				:countLabel="rangeLabel"
				:loading="loading"
				data-testid="traffic-tile-visitors" />
			<CnStatsBlock
				:title="t('portaliq', 'Engaged sessions')"
				:count="summary.totals.engagedSessions"
				:countLabel="rangeLabel"
				:loading="loading"
				variant="success"
				data-testid="traffic-tile-engaged" />
		</div>
	</div>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import {
	NcButton,
	NcDateTimePickerNative,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import Download from 'vue-material-design-icons/Download.vue'
import TrafficEmptyState from './TrafficEmptyState.vue'
import { rollupOf, warnedSwitches } from '../lib/trafficSummary.js'
import { RANGE_PRESETS } from '../store/traffic.js'
import trafficWidgetMixin from './trafficWidgetMixin.js'

export default {
	name: 'TrafficOverview',

	components: {
		CnStatsBlock,
		Download,
		NcButton,
		NcDateTimePickerNative,
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
		 * The range presets plus the custom entry, as select options.
		 *
		 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-traffic-page-must-let-the-reader-choose-the-range
		 * @return {Array<{id: string, label: string}>} The options.
		 */
		rangeOptions() {
			return RANGE_PRESETS.map((days) => ({
				id: days,
				label: this.n(
					'portaliq',
					'Last %n day',
					'Last %n days',
					Number(days),
				),
			})).concat([
				{ id: 'custom', label: this.t('portaliq', 'Custom period') },
			])
		},

		/**
		 * The selected range option.
		 *
		 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-traffic-page-must-let-the-reader-choose-the-range
		 * @return {object|null} The option.
		 */
		selectedRange() {
			return (
				this.rangeOptions.find((o) => o.id === this.report.rangePreset)
				|| null
			)
		},

		/**
		 * The custom start as a Date for the picker, or null.
		 *
		 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-traffic-page-must-let-the-reader-choose-the-range
		 * @return {Date|null} The date.
		 */
		customFrom() {
			return this.asDate(this.report.customFrom)
		},

		/**
		 * The custom end as a Date for the picker, or null.
		 *
		 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-traffic-page-must-let-the-reader-choose-the-range
		 * @return {Date|null} The date.
		 */
		customTo() {
			return this.asDate(this.report.customTo)
		},

		/**
		 * "All visits" plus the portal's segments, as select options.
		 *
		 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-segment-must-be-a-saved-filter-over-sessions
		 * @return {Array<{id: string, label: string}>} The options.
		 */
		segmentOptions() {
			return [{ id: '', label: this.t('portaliq', 'All visits') }].concat(
				this.report.segments.map((s) => ({ id: s.id, label: s.name })),
			)
		},

		/**
		 * The selected segment option.
		 *
		 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-segment-must-be-a-saved-filter-over-sessions
		 * @return {object|null} The option.
		 */
		selectedSegment() {
			return (
				this.segmentOptions.find((o) => o.id === this.report.segment)
				|| this.segmentOptions[0]
			)
		},

		/**
		 * The portals a roll-up portal sums, or [].
		 *
		 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
		 * @return {Array<string>} The member slugs.
		 */
		members() {
			return rollupOf(this.portal)
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
		 * Switch the page to a segment, or back to all visits.
		 *
		 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-segment-must-be-a-saved-filter-over-sessions
		 * @param {object|null} option The chosen option.
		 * @return {void}
		 */
		onSegment(option) {
			if (option) {
				this.report.setSegment(option.id)
			}
		},

		/**
		 * Choose a range preset, or open the custom pickers.
		 *
		 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-traffic-page-must-let-the-reader-choose-the-range
		 * @param {object|null} option The chosen option.
		 * @return {void}
		 */
		onRange(option) {
			if (option && option.id) {
				this.report.setRange(option.id)
			}
		},

		/**
		 * One end of the custom range changed.
		 *
		 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-traffic-page-must-let-the-reader-choose-the-range
		 * @param {string}    end   'from' or 'to'.
		 * @param {Date|null} value The picked date.
		 * @return {void}
		 */
		onCustom(end, value) {
			const day = this.asDay(value)
			const from = end === 'from' ? day : this.report.customFrom
			const to = end === 'to' ? day : this.report.customTo
			this.report.setCustomRange(from, to)
		},

		/**
		 * A YYYY-MM-DD string as a local Date, or null.
		 *
		 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-traffic-page-must-let-the-reader-choose-the-range
		 * @param {string} day The day.
		 * @return {Date|null} The date.
		 */
		asDate(day) {
			if (!/^\d{4}-\d{2}-\d{2}$/.test(String(day || ''))) {
				return null
			}
			const [y, m, d] = String(day).split('-').map(Number)
			return new Date(y, m - 1, d)
		},

		/**
		 * A picked Date as YYYY-MM-DD in local time, or ''.
		 *
		 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-traffic-page-must-let-the-reader-choose-the-range
		 * @param {Date|null} value The date.
		 * @return {string} The day.
		 */
		asDay(value) {
			if (!(value instanceof Date) || Number.isNaN(value.getTime())) {
				return ''
			}
			const pad = (n) => String(n).padStart(2, '0')
			return (
				value.getFullYear()
				+ '-'
				+ pad(value.getMonth() + 1)
				+ '-'
				+ pad(value.getDate())
			)
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
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: flex-end;
}

.traffic-overview__toolbar > * {
	min-width: 200px;
	max-width: 320px;
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
