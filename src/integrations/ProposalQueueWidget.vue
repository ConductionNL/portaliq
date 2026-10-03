<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The review surface of the change-proposal queue, as the
  `portaliq-change-proposal-queue` leaf (change-proposal-queue T06).

  Placed by another app on a record's detail page, it lists the proposals
  waiting on that record with the value now and the value proposed. A reviewer
  accepts one, is asked again when the record moved since, or rejects one with
  a reason. Portaliq's review routes take the decision and write the record
  through OpenRegister as the reviewer; the host app is never called.

  Kept to plain elements: this component lands on other apps' pages through the
  `portaliq-leaves` bundle, which carries no component library.
-->
<template>
	<div class="portaliq-proposal-queue">
		<p
			v-if="state === 'loading'"
			class="portaliq-proposal-queue__state"
			role="status">
			{{ t('portaliq', 'Loading proposals…') }}
		</p>
		<p v-else-if="state === 'forbidden'" class="portaliq-proposal-queue__state">
			{{ t('portaliq', 'You cannot review proposals on this record.') }}
		</p>
		<p
			v-else-if="state === 'error'"
			class="portaliq-proposal-queue__state portaliq-proposal-queue__state--error"
			role="alert">
			{{ t('portaliq', 'The proposals could not be loaded.') }}
		</p>
		<p v-else-if="proposals.length === 0" class="portaliq-proposal-queue__state">
			{{ t('portaliq', 'No proposals are waiting on this record.') }}
		</p>
		<ul v-else class="portaliq-proposal-queue__list">
			<li
				v-for="proposal in proposals"
				:key="proposal.uuid || proposal.id"
				class="portaliq-proposal-queue__item">
				<p class="portaliq-proposal-queue__meta">
					{{
						proposal.channel === 'portal'
							? t('portaliq', 'From the portal')
							: t('portaliq', 'From a colleague')
					}}
					<span v-if="proposal.proposedAt">
						· {{ dateOf(proposal.proposedAt) }}</span
					>
				</p>
				<p v-if="proposal.note" class="portaliq-proposal-queue__note">
					{{ proposal.note }}
				</p>
				<table class="portaliq-proposal-queue__changes">
					<thead>
						<tr>
							<th scope="col">
								{{ t('portaliq', 'Field') }}
							</th>
							<th scope="col">
								{{ t('portaliq', 'Now') }}
							</th>
							<th scope="col">
								{{ t('portaliq', 'Proposed') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="change in proposal.changes || []"
							:key="change.property">
							<td>{{ change.property }}</td>
							<td>{{ formatValue(change.currentValue) }}</td>
							<td>{{ formatValue(change.proposedValue) }}</td>
						</tr>
					</tbody>
				</table>

				<div
					v-if="driftFor(proposal).length > 0"
					class="portaliq-proposal-queue__drift"
					role="alert">
					<p>
						{{
							t(
								'portaliq',
								'This record changed after the proposal was made.',
							)
						}}
					</p>
					<ul>
						<li
							v-for="moved in driftFor(proposal)"
							:key="moved.property">
							{{
								t(
									'portaliq',
									'{field} was {snapshot} and is now {current}.',
									{
										field: moved.property,
										snapshot: formatValue(moved.snapshot),
										current: formatValue(moved.current),
									},
								)
							}}
						</li>
					</ul>
					<button
						type="button"
						class="primary"
						:disabled="busy"
						@click="acceptAnyway(proposal)">
						{{ t('portaliq', 'Accept anyway') }}
					</button>
				</div>

				<div
					v-if="rejecting === idOf(proposal)"
					class="portaliq-proposal-queue__reject">
					<label :for="'portaliq-reject-' + idOf(proposal)">{{
						t('portaliq', 'Reason for rejecting')
					}}</label>
					<textarea
						:id="'portaliq-reject-' + idOf(proposal)"
						v-model="reason"
						rows="2" />
					<button type="button" :disabled="busy" @click="reject(proposal)">
						{{ t('portaliq', 'Reject with this reason') }}
					</button>
					<button type="button" :disabled="busy" @click="rejecting = ''">
						{{ t('portaliq', 'Cancel') }}
					</button>
				</div>
				<div
					v-else-if="driftFor(proposal).length === 0"
					class="portaliq-proposal-queue__actions">
					<button
						type="button"
						class="primary"
						:disabled="busy"
						@click="accept(proposal)">
						{{ t('portaliq', 'Accept') }}
					</button>
					<button
						type="button"
						:disabled="busy"
						@click="startRejecting(proposal)">
						{{ t('portaliq', 'Reject') }}
					</button>
				</div>
			</li>
		</ul>
		<p
			v-if="notice"
			class="portaliq-proposal-queue__notice"
			:role="noticeIsError ? 'alert' : 'status'">
			{{ notice }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { createProposalQueue, formatValue } from './proposalQueue.js'

export default {
	name: 'ProposalQueueWidget',

	props: {
		/** The host record's register. */
		register: { type: String, default: '' },
		/** The host record's schema. */
		schema: { type: String, default: '' },
		/** The host record. */
		objectId: { type: String, default: '' },
	},

	data() {
		return {
			state: 'loading',
			proposals: [],
			drift: {},
			rejecting: '',
			reason: '',
			busy: false,
			notice: '',
			noticeIsError: false,
		}
	},

	/**
	 * @spec openspec/specs/change-proposal-queue/spec.md#requirement-the-queue-is-a-leaf-on-the-subject-req-cpq-004
	 */
	created() {
		this.queue = createProposalQueue({
			get: (url, config) => axios.get(url, config),
			post: (url, body) => axios.post(url, body),
			url: (path, params) => generateUrl('/apps/portaliq' + path, params),
		})
		this.load()
	},

	methods: {
		t,
		formatValue,

		/**
		 * @param {object} proposal The proposal.
		 * @return {string}
		 *
		 * @spec openspec/specs/change-proposal-queue/spec.md#requirement-the-queue-is-a-leaf-on-the-subject-req-cpq-004
		 */
		idOf(proposal) {
			return String(proposal?.uuid || proposal?.id || '')
		},

		/**
		 * @param {string} value An ISO date.
		 * @return {string}
		 *
		 * @spec openspec/specs/change-proposal-queue/spec.md#requirement-the-queue-is-a-leaf-on-the-subject-req-cpq-004
		 */
		dateOf(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime())
				? String(value)
				: date.toLocaleDateString()
		},

		/**
		 * @param {object} proposal The proposal.
		 * @return {Array}
		 *
		 * @spec openspec/specs/change-proposal-queue/spec.md#requirement-the-queue-is-a-leaf-on-the-subject-req-cpq-004
		 */
		driftFor(proposal) {
			return this.drift[this.idOf(proposal)] || []
		},

		/**
		 * Read the queue for the host record.
		 *
		 * @spec openspec/specs/change-proposal-queue/spec.md#requirement-the-queue-is-a-leaf-on-the-subject-req-cpq-004
		 */
		async load() {
			const result = await this.queue.load({
				register: this.register,
				schema: this.schema,
				objectId: this.objectId,
			})
			this.state = result.state
			this.proposals = result.proposals
		},

		/**
		 * @param {object} proposal The proposal.
		 *
		 * @spec openspec/specs/change-proposal-queue/spec.md#requirement-the-queue-is-a-leaf-on-the-subject-req-cpq-004
		 */
		startRejecting(proposal) {
			this.rejecting = this.idOf(proposal)
			this.reason = ''
			this.notice = ''
		},

		/**
		 * @param {object} proposal The proposal.
		 *
		 * @spec openspec/specs/change-proposal-queue/spec.md#requirement-the-queue-is-a-leaf-on-the-subject-req-cpq-004
		 */
		async accept(proposal) {
			await this.settle(proposal, () => this.queue.accept(proposal))
		},

		/**
		 * @param {object} proposal The proposal.
		 *
		 * @spec openspec/specs/change-proposal-queue/spec.md#requirement-the-queue-is-a-leaf-on-the-subject-req-cpq-004
		 */
		async acceptAnyway(proposal) {
			await this.settle(proposal, () => this.queue.acceptAnyway(proposal))
		},

		/**
		 * @param {object} proposal The proposal.
		 *
		 * @spec openspec/specs/change-proposal-queue/spec.md#requirement-the-queue-is-a-leaf-on-the-subject-req-cpq-004
		 */
		async reject(proposal) {
			await this.settle(proposal, () =>
				this.queue.reject(proposal, this.reason),
			)
		},

		/**
		 * Run one decision and say how it went.
		 *
		 * @param {object} proposal The proposal.
		 * @param {Function} decision The decision to run.
		 *
		 * @spec openspec/specs/change-proposal-queue/spec.md#requirement-the-queue-is-a-leaf-on-the-subject-req-cpq-004
		 */
		async settle(proposal, decision) {
			this.busy = true
			const result = await decision()
			this.busy = false
			const id = this.idOf(proposal)
			this.noticeIsError = false
			if (result.outcome === 'drifted') {
				this.drift = { ...this.drift, [id]: result.drift }
				this.notice = ''
				return
			}
			if (result.outcome === 'reasonRequired') {
				this.noticeIsError = true
				this.notice = t('portaliq', 'Give a reason to reject.')
				return
			}
			if (result.outcome === 'refused') {
				this.noticeIsError = true
				this.notice = t('portaliq', 'This decision was refused.')
				return
			}
			this.notice =
				result.outcome === 'accepted'
					? t('portaliq', 'The change is saved on the record.')
					: t('portaliq', 'The proposal is rejected.')
			this.rejecting = ''
			const { [id]: _settled, ...rest } = this.drift
			this.drift = rest
			this.proposals = this.proposals.filter((row) => this.idOf(row) !== id)
		},
	},
}
</script>

<style scoped>
.portaliq-proposal-queue__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.portaliq-proposal-queue__item {
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.portaliq-proposal-queue__meta,
.portaliq-proposal-queue__state {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.portaliq-proposal-queue__state--error {
	color: var(--color-error);
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

.portaliq-proposal-queue__drift {
	border-inline-start: 4px solid var(--color-warning);
	padding-inline-start: 8px;
	margin: 4px 0;
}

.portaliq-proposal-queue__reject textarea {
	display: block;
	width: 100%;
}

.portaliq-proposal-queue__actions,
.portaliq-proposal-queue__reject {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}
</style>
