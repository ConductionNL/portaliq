<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div class="pq-proposals" data-testid="proposal-queue">
		<ul
			v-if="queue.length > 0"
			class="pq-proposals__list"
			:aria-label="translate('Your proposals')">
			<li
				v-for="proposal in queue"
				:key="proposal.uuid || proposal.id"
				class="pq-proposals__item">
				<span>{{ summary(proposal) }}</span>
				<span class="pq-proposals__state">{{
					translate(stateKey(proposal.state))
				}}</span>
				<button
					v-if="proposal.state === 'queued'"
					type="button"
					class="utrecht-button utrecht-button--subtle"
					:disabled="busyId === (proposal.uuid || proposal.id)"
					@click="withdraw(proposal.uuid || proposal.id)">
					{{ translate('Withdraw') }}
				</button>
			</li>
		</ul>

		<ProposeChangeForm
			v-if="showForm"
			:action="action"
			:row="row"
			:send="send"
			:t="t"
			@cancel="showForm = false" />
		<button
			v-else
			type="button"
			class="utrecht-button utrecht-button--secondary-action"
			data-testid="propose-open"
			@click="showForm = true">
			{{ translate('Propose a change') }}
		</button>
	</div>
</template>

<script>
import ProposeChangeForm from './ProposeChangeForm.vue'
import { proposalsOn, proposalStateKey, rowIdOf, translatorOr } from './forms.js'

/**
 * The resident's own proposals on one record, and the form to add another
 * (the ProposalQueue of PageView.jsx). The list comes from the bearer's own
 * proposals, filtered here to this record; the server re-checks ownership on
 * every read and write.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-propose-a-change-req-srp-024
 */
export default {
	name: 'ProposalQueue',

	components: { ProposeChangeForm },

	props: {
		/** The `propose-change` action. */
		action: { type: Object, required: true },
		/** The record on screen. */
		row: { type: Object, default: null },
		/** The portal api: `fetchMyProposals`, `proposeChange`, `withdrawProposal`. */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, default: null },
	},

	data() {
		return {
			queue: [],
			showForm: false,
			busyId: null,
		}
	},

	computed: {
		translate() {
			return translatorOr(this.t)
		},

		rowId() {
			return rowIdOf(this.row)
		},
	},

	watch: {
		rowId() {
			this.refresh()
		},
	},

	mounted() {
		this.refresh()
	},

	methods: {
		stateKey(state) {
			return proposalStateKey(state)
		},

		summary(proposal) {
			return (proposal.changes || [])
				.map((c) => `${c.property}: ${c.proposedValue}`)
				.join(', ')
		},

		/**
		 * Read this subject's proposals on the record.
		 *
		 * @return {Promise<void>}
		 */
		async refresh() {
			if (!this.rowId) {
				this.queue = []
				return
			}
			const mine = await this.api.fetchMyProposals()
			this.queue = proposalsOn(mine, this.action, this.rowId)
		},

		/**
		 * Send a proposal; on success close the form and read the list again.
		 *
		 * @param {Array<object>} changes The changed fields.
		 * @param {string} note The note.
		 * @return {Promise<object>} The api's answer.
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-propose-a-change-req-srp-024
		 */
		async send(changes, note) {
			const result = await this.api.proposeChange(
				this.action,
				this.rowId,
				changes,
				note,
			)
			if (result && result.ok) {
				this.showForm = false
				this.refresh()
			}
			return result
		},

		/**
		 * Withdraw a queued proposal.
		 *
		 * @param {string} id The proposal.
		 * @return {Promise<void>}
		 */
		async withdraw(id) {
			this.busyId = id
			await this.api.withdrawProposal(id)
			this.busyId = null
			this.refresh()
		},
	},
}
</script>

<style scoped>
.pq-proposals__list {
	padding-inline-start: 0;
	list-style: none;
}

.pq-proposals__item {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	margin-block-end: var(--utrecht-space-block-sm, 0.5rem);
}

.pq-proposals__state {
	font-weight: var(--utrecht-typography-weight-scale-bold, bold);
}
</style>
