<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  One proposal as both views of the change-proposal queue show it: who or
  what it is, when it was made, its note, and per field the value proposed,
  with the value now when the reviewer's view asks for it
  (change-proposals-reach-the-case-page).

  Kept to plain elements, like the queue widget it is part of.
-->
<template>
	<div class="portaliq-proposal-summary">
		<p class="portaliq-proposal-queue__meta">
			{{ label }}
			<span v-if="proposedAt">· {{ dateOf(proposedAt) }}</span>
		</p>
		<p v-if="note" class="portaliq-proposal-queue__note">
			{{ note }}
		</p>
		<table class="portaliq-proposal-queue__changes">
			<thead>
				<tr>
					<th scope="col">
						{{ t('portaliq', 'Field') }}
					</th>
					<th v-if="showCurrent" scope="col">
						{{ t('portaliq', 'Now') }}
					</th>
					<th scope="col">
						{{ t('portaliq', 'Proposed') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="change in changes" :key="change.property">
					<td>{{ change.property }}</td>
					<td v-if="showCurrent">
						{{ formatValue(change.currentValue) }}
					</td>
					<td>{{ formatValue(change.proposedValue) }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { formatDate, formatValue } from './proposalQueue.js'

export default {
	name: 'ProposalSummary',

	props: {
		/** Where the proposal came from, or the state it is in. */
		label: { type: String, default: '' },
		/** When the proposal was made, as an ISO date. */
		proposedAt: { type: String, default: '' },
		/** The proposer's note. */
		note: { type: String, default: '' },
		/** The proposed changes: [{property, currentValue, proposedValue}]. */
		changes: { type: Array, default: () => [] },
		/** Whether to show each field's value now next to the proposed one. */
		showCurrent: { type: Boolean, default: false },
	},

	methods: {
		t,
		formatValue,
		dateOf: formatDate,
	},
}
</script>

<style scoped>
.portaliq-proposal-queue__meta {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.portaliq-proposal-queue__changes {
	width: 100%;
	margin: 4px 0;
	border-collapse: collapse;
}

.portaliq-proposal-queue__changes th,
.portaliq-proposal-queue__changes td {
	text-align: start;
	padding: 2px 8px 2px 0;
}
</style>
