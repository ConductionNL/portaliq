<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AvailabilityReport: how available one portal was over the last twelve full
  months, for a service level review (operate-availability-report).

  Reads AvailabilityController, which is admin-only. The figures come from the
  portal's own check every five minutes; an interval without a check counts as
  down. The paragraph "How this is measured" says what the figure covers and
  what it cannot see.

  @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
-->
<template>
	<div class="availability" data-testid="availability-report">
		<div class="availability__toolbar">
			<NcSelect
				:modelValue="selected"
				:inputLabel="t('portaliq', 'Portal')"
				:options="portals"
				:clearable="false"
				label="label"
				data-testid="availability-portal-select"
				@update:modelValue="onSelect" />
			<NcButton
				v-if="selected"
				:href="downloadUrl"
				variant="secondary"
				data-testid="availability-download">
				{{ t('portaliq', 'Download as CSV') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-else-if="report">
			<table class="availability__table" data-testid="availability-months">
				<caption>
					{{
						t('portaliq', 'Availability per month, {from} to {until}', {
							from: report.from,
							until: report.until,
						})
					}}
				</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('portaliq', 'Month') }}
						</th>
						<th scope="col">
							{{ t('portaliq', 'Availability') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="month in report.months" :key="month.month">
						<td>{{ month.month }}</td>
						<td>{{ percent(month.percentage) }}</td>
					</tr>
				</tbody>
			</table>

			<h3>{{ t('portaliq', 'Outages') }}</h3>
			<p v-if="report.outages.length === 0">
				{{ t('portaliq', 'No outages in this period.') }}
			</p>
			<table
				v-else
				class="availability__table"
				data-testid="availability-outages">
				<thead>
					<tr>
						<th scope="col">
							{{ t('portaliq', 'Started') }}
						</th>
						<th scope="col">
							{{ t('portaliq', 'Ended') }}
						</th>
						<th scope="col">
							{{ t('portaliq', 'Duration') }}
						</th>
						<th scope="col">
							{{ t('portaliq', 'Cause') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="outage in report.outages" :key="outage.startedAt">
						<td>{{ outage.startedAt }}</td>
						<td>
							{{ outage.endedAt || t('portaliq', 'Still going on') }}
						</td>
						<td>{{ duration(outage.durationMinutes) }}</td>
						<td>{{ cause(outage.cause) }}</td>
					</tr>
				</tbody>
			</table>
		</template>

		<h3>{{ t('portaliq', 'How this is measured') }}</h3>
		<p data-testid="availability-how">
			{{
				t(
					'portaliq',
					"Every five minutes the portal opens its own public site, through this installation's web address, the way a visitor would. It counts as available when the site answers within five seconds and its health check says ok. An interval in which no check ran counts as down. The check runs inside the installation, so it does not see an outage of the network in front of it. The hosting party's own monitor stays the reference for that.",
				)
			}}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import {
	availabilityUrl,
	causeLabel,
	durationLabel,
	exportUrl,
	percentLabel,
	portalsFrom,
} from '../lib/availabilityReport.js'

export default {
	name: 'AvailabilityReport',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			portals: [],
			selected: null,
			report: null,
			loading: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The CSV download of the selected portal's report.
		 *
		 * @return {string}
		 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
		 */
		downloadUrl() {
			return this.selected ? exportUrl(this.selected.id, generateUrl) : ''
		},
	},

	mounted() {
		this.loadPortals()
	},

	methods: {
		/**
		 * Read the published portals and pick the first.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
		 */
		async loadPortals() {
			try {
				const { data } = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/portaliq/portal?status=published&_limit=100',
					),
				)
				this.portals = portalsFrom(data)
				if (this.portals.length > 0) {
					this.onSelect(this.portals[0])
				}
			} catch {
				this.error = t('portaliq', 'The portals could not be loaded.')
			}
		},

		/**
		 * Show one portal's report.
		 *
		 * @param {object} option The picked portal.
		 * @return {Promise<void>}
		 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
		 */
		async onSelect(option) {
			if (!option) {
				return
			}
			this.selected = option
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(
					availabilityUrl(option.id, generateUrl),
				)
				this.report = data
			} catch {
				this.report = null
				this.error = t(
					'portaliq',
					'The availability of this portal could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * A month's figure as text.
		 *
		 * @param {number|null} value The percentage.
		 * @return {string}
		 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
		 */
		percent(value) {
			return percentLabel(value, t)
		},

		/**
		 * An outage's cause as text.
		 *
		 * @param {string} value The cause.
		 * @return {string}
		 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
		 */
		cause(value) {
			return causeLabel(value, t)
		},

		/**
		 * An outage's length as text.
		 *
		 * @param {number} value Minutes.
		 * @return {string}
		 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
		 */
		duration(value) {
			return durationLabel(value, t)
		},
	},
}
</script>

<style scoped>
.availability {
	padding: calc(var(--default-grid-baseline) * 2);
}

.availability__toolbar {
	display: flex;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 4);
	flex-wrap: wrap;
}

.availability__table {
	width: 100%;
	max-width: 720px;
	border-collapse: collapse;
	margin: calc(var(--default-grid-baseline) * 4) 0;
}

.availability__table caption {
	text-align: start;
	font-weight: bold;
}

.availability__table th,
.availability__table td {
	text-align: start;
	padding: calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
}
</style>
