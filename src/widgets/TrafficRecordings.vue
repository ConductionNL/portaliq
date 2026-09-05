<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  TrafficRecordings — the session recordings of the selected portal
  (portal-traffic-experiments): when each visit started, the pages it
  went through, how long it lasted, and a player.

  Lists the newest hundred. A portal that does not record is told so
  rather than shown an empty list, because "no recordings" and "not
  recording" are different facts and only one of them is a privacy
  posture the operator chose.

  @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-session-recording-must-be-off-by-default-consented-and-bounded
-->
<template>
	<div class="traffic-table" data-testid="traffic-recordings">
		<TrafficEmptyState :state="emptyState" />

		<p
			v-if="emptyState === '' && !report.recordingOn"
			class="traffic-table__muted"
			data-testid="traffic-recordings-off">
			{{
				t(
					'portaliq',
					'Session recording is off for this portal. Switch it on under sensitive measurement in the portal settings; the switch carries its own warning.',
				)
			}}
		</p>

		<template v-else-if="emptyState === ''">
			<p
				class="traffic-table__muted"
				data-testid="traffic-recordings-retention">
				{{
					n(
						'portaliq',
						'Recordings are kept for %n day, then deleted with the raw events.',
						'Recordings are kept for %n days, then deleted with the raw events.',
						report.retentionDays,
					)
				}}
			</p>
			<table
				class="traffic-table__table"
				data-testid="traffic-recordings-table">
				<thead>
					<tr>
						<th scope="col">{{ t('portaliq', 'Started') }}</th>
						<th scope="col">{{ t('portaliq', 'Pages') }}</th>
						<th scope="col" class="traffic-table__number">
							{{ t('portaliq', 'Duration') }}
						</th>
						<th scope="col">
							<span class="hidden-visually">{{
								t('portaliq', 'Play')
							}}</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="recording in report.recordings"
						:key="recording.recordingId"
						data-testid="traffic-recording-row">
						<td class="traffic-table__number">
							{{ when(recording.startedAt) }}
						</td>
						<td class="traffic-table__path">{{ pages(recording) }}</td>
						<td class="traffic-table__number">
							{{ duration(recording) }}
						</td>
						<td>
							<NcButton
								variant="tertiary"
								:aria-label="t('portaliq', 'Play this recording')"
								data-testid="traffic-recording-play"
								@click="play(recording)">
								<template #icon>
									<Play :size="20" />
								</template>
							</NcButton>
						</td>
					</tr>
					<tr
						v-if="
							report.recordings.length === 0
							&& !report.loadingRecordings
						">
						<td
							colspan="4"
							class="traffic-table__muted"
							data-testid="traffic-recordings-empty">
							{{
								t(
									'portaliq',
									'No recordings yet. The first one appears after a visit with consent.',
								)
							}}
						</td>
					</tr>
				</tbody>
			</table>
		</template>

		<TrafficRecordingPlayer
			:open="playing !== null"
			:recording="playing"
			@update:open="onPlayerOpen" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import Play from 'vue-material-design-icons/Play.vue'
import TrafficRecordingPlayer from '../modals/TrafficRecordingPlayer.vue'
import TrafficEmptyState from './TrafficEmptyState.vue'
import trafficWidgetMixin from './trafficWidgetMixin.js'

export default {
	name: 'TrafficRecordings',

	components: {
		NcButton,
		Play,
		TrafficEmptyState,
		TrafficRecordingPlayer,
	},

	mixins: [trafficWidgetMixin],

	data() {
		return {
			playing: null,
		}
	},

	methods: {
		/**
		 * A recording's start as a readable local moment.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-session-recording-must-be-off-by-default-consented-and-bounded
		 * @param {string} iso The instant.
		 * @return {string} The label.
		 */
		when(iso) {
			const date = new Date(String(iso || ''))
			if (Number.isNaN(date.getTime())) {
				return String(iso || '')
			}
			return date.toLocaleString()
		},

		/**
		 * The pages a recording went through, at most three shown.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-session-recording-must-be-off-by-default-consented-and-bounded
		 * @param {object} recording The recording.
		 * @return {string} The label.
		 */
		pages(recording) {
			const pages = Array.isArray(recording.pages) ? recording.pages : []
			if (pages.length <= 3) {
				return pages.join(', ')
			}
			return this.t('portaliq', '{first} and {more} more', {
				first: pages.slice(0, 3).join(', '),
				more: pages.length - 3,
			})
		},

		/**
		 * A recording's length as m:ss.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-session-recording-must-be-off-by-default-consented-and-bounded
		 * @param {object} recording The recording.
		 * @return {string} The clock.
		 */
		duration(recording) {
			const seconds = Math.max(
				0,
				Math.round((Number(recording.durationMs) || 0) / 1000),
			)
			return (
				Math.floor(seconds / 60)
				+ ':'
				+ String(seconds % 60).padStart(2, '0')
			)
		},

		/**
		 * Open the player on a recording.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-session-recording-must-be-off-by-default-consented-and-bounded
		 * @param {object} recording The recording.
		 * @return {void}
		 */
		play(recording) {
			this.playing = recording
		},

		/**
		 * The player closed.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-session-recording-must-be-off-by-default-consented-and-bounded
		 * @param {boolean} open Whether it is open.
		 * @return {void}
		 */
		onPlayerOpen(open) {
			if (!open) {
				this.playing = null
			}
		},
	},
}
</script>

<style scoped src="./trafficTable.css"></style>
