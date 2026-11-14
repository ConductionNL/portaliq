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

  A colleague who may read the record but not review its proposals gets
  "Wijziging voorstellen" instead: the declared proposable properties
  prefilled with their values now and a note, sending only what changed, and
  below it their own proposals on the record with a withdraw button for a
  queued one. A user who may not read the record sees nothing
  (change-proposals-reach-the-case-page).

  Kept to plain elements: this component lands on other apps' pages through the
  `portaliq-leaves` bundle, which carries no component library.
-->
<template>
	<div v-if="!summarised" class="portaliq-proposal-queue">
		<template v-if="view === 'review'">
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
		<p v-else-if="proposals.length === 0 && !carded" class="portaliq-proposal-queue__state">
			{{ t('portaliq', 'No proposals are waiting on this record.') }}
		</p>
		<ul v-else-if="proposals.length > 0" class="portaliq-proposal-queue__list">
			<li
				v-for="proposal in proposals"
				:key="proposal.uuid || proposal.id"
				class="portaliq-proposal-queue__item">
				<ProposalSummary
					:label="channelLabel(proposal)"
					:proposed-at="proposal.proposedAt || ''"
					:note="proposal.note || ''"
					:changes="proposal.changes || []"
					show-current />

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
		</template>
		<p
			v-else-if="view === 'loading'"
			class="portaliq-proposal-queue__state"
			role="status">
			{{ t('portaliq', 'Loading proposals…') }}
		</p>
		<p
			v-else-if="view === 'error'"
			class="portaliq-proposal-queue__state portaliq-proposal-queue__state--error"
			role="alert">
			{{ t('portaliq', 'The proposals could not be loaded.') }}
		</p>
		<template v-else-if="view === 'propose'">
			<form class="portaliq-proposal-queue__propose" @submit.prevent="propose">
				<h3 class="portaliq-proposal-queue__heading">
					{{ t('portaliq', 'Propose a change') }}
				</h3>
				<p v-if="fields.length === 0" class="portaliq-proposal-queue__state">
					{{ t('portaliq', 'Nothing on this record can be proposed.') }}
				</p>
				<template v-else>
					<div
						v-for="field in fields"
						:key="field.property"
						class="portaliq-proposal-queue__field">
						<label :for="inputId(field.property)">{{ field.property }}</label>
						<input
							:id="inputId(field.property)"
							v-model="values[field.property]"
							type="text">
					</div>
					<div class="portaliq-proposal-queue__field">
						<label :for="inputId('note')">{{ t('portaliq', 'Note for the reviewer') }}</label>
						<textarea
							:id="inputId('note')"
							v-model="note"
							rows="2" />
					</div>
					<button type="submit" class="primary" :disabled="busy">
						{{ t('portaliq', 'Send proposal') }}
					</button>
				</template>
			</form>
			<h3 class="portaliq-proposal-queue__heading">
				{{ t('portaliq', 'Your proposals on this record') }}
			</h3>
			<p
				v-if="mineState === 'error'"
				class="portaliq-proposal-queue__state portaliq-proposal-queue__state--error"
				role="alert">
				{{ t('portaliq', 'Your proposals could not be loaded.') }}
			</p>
			<p v-else-if="mine.length === 0" class="portaliq-proposal-queue__state">
				{{ t('portaliq', 'You have made no proposals on this record.') }}
			</p>
			<ul v-else class="portaliq-proposal-queue__list">
				<li
					v-for="proposal in mine"
					:key="idOf(proposal)"
					class="portaliq-proposal-queue__item">
					<ProposalSummary
						:changes="proposal.changes || []"
						:note="proposal.note || ''"
						:proposed-at="proposal.proposedAt || ''"
						:label="stateLabel(proposal.state)" />
					<div
						v-if="proposal.state === 'queued'"
						class="portaliq-proposal-queue__actions">
						<button type="button" :disabled="busy" @click="withdraw(proposal)">
							{{ t('portaliq', 'Withdraw') }}
						</button>
					</div>
				</li>
			</ul>
		</template>
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
import { leafReportMixin } from './leafReport.js'
import { createProposalQueue, formatDate, formatValue, inputValue } from './proposalQueue.js'
import ProposalSummary from './ProposalSummary.vue'

export default {
	name: 'ProposalQueueWidget',

	components: { ProposalSummary },

	mixins: [leafReportMixin],

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
			view: 'loading',
			state: 'loading',
			proposals: [],
			fields: [],
			values: {},
			note: '',
			mine: [],
			mineState: 'loading',
			drift: {},
			rejecting: '',
			reason: '',
			busy: false,
			notice: '',
			noticeIsError: false,
		}
	},

	computed: {
		/** @return {boolean} Whether the queue is still being read. */
		loading() {
			if (this.view === 'propose') {
				return this.mineState === 'loading'
			}
			return this.view === 'loading' || (this.view === 'review' && this.state === 'loading')
		},

		/** @return {string} Why the queue could not be read, or ''. */
		error() {
			if (this.view === 'hidden') {
				return 'forbidden'
			}
			if (this.view === 'error') {
				return 'error'
			}
			if (this.view === 'propose') {
				return this.mineState === 'error' ? 'error' : ''
			}
			return this.state === 'forbidden' || this.state === 'error' ? this.state : ''
		},

		/**
		 * What this leaf reports to a carding host or a "linked" card.
		 *
		 * @return {object} The report.
		 * @spec openspec/specs/leaves-library-card/spec.md#requirement-the-proposal-queue-reports-to-the-library-card-and-the-linked-card
		 */
		leafReport() {
			if (this.view === 'propose') {
				return {
					count: this.mine.length,
					emptyLabel: t('portaliq', 'You have made no proposals on this record.'),
					noun: t('portaliq', 'change proposals'),
					rows: this.mine.map((proposal) => ({
						label: this.stateLabel(proposal.state)
							+ (proposal.proposedAt ? ` · ${this.dateOf(proposal.proposedAt)}` : ''),
						icon: 'FileDocumentEditOutline',
					})),
				}
			}
			return {
				count: this.proposals.length,
				emptyLabel: t('portaliq', 'No proposals are waiting on this record.'),
				noun: t('portaliq', 'change proposals'),
				rows: this.proposals.map((proposal) => ({
					label: this.channelLabel(proposal)
						+ (proposal.proposedAt ? ` · ${this.dateOf(proposal.proposedAt)}` : ''),
					icon: 'FileDocumentEditOutline',
				})),
			}
		},
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
		this.start()
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
		 * @param {object} proposal The proposal.
		 * @return {string} Where the proposal came from.
		 *
		 * @spec openspec/specs/change-proposal-queue/spec.md#requirement-the-queue-is-a-leaf-on-the-subject-req-cpq-004
		 */
		channelLabel(proposal) {
			return proposal.channel === 'portal'
				? t('portaliq', 'From the portal')
				: t('portaliq', 'From a colleague')
		},

		dateOf: formatDate,

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
		 * Ask which view this user gets, then read what that view shows.
		 *
		 * @spec openspec/changes/change-proposals-reach-the-case-page/specs/change-proposal-queue/spec.md#requirement-a-colleague-who-may-read-a-record-proposes-from-the-queue-widget-req-cpc-001
		 */
		async start() {
			const context = await this.queue.loadContext(this.host())
			this.view = context.view
			if (context.view === 'review') {
				await this.load()
				return
			}
			if (context.view === 'propose') {
				this.fields = context.fields
				this.resetValues()
				await this.loadMine()
			}
		},

		/**
		 * @return {object} The host record.
		 *
		 * @spec openspec/changes/change-proposals-reach-the-case-page/specs/change-proposal-queue/spec.md#requirement-a-colleague-who-may-read-a-record-proposes-from-the-queue-widget-req-cpc-001
		 */
		host() {
			return { register: this.register, schema: this.schema, objectId: this.objectId }
		},

		/**
		 * Prefill every proposable field with its value now, and clear the note.
		 *
		 * @spec openspec/changes/change-proposals-reach-the-case-page/specs/change-proposal-queue/spec.md#requirement-a-colleague-who-may-read-a-record-proposes-from-the-queue-widget-req-cpc-001
		 */
		resetValues() {
			const values = {}
			for (const field of this.fields) {
				values[field.property] = inputValue(field.value)
			}
			this.values = values
			this.note = ''
		},

		/**
		 * Read the colleague's own proposals on the host record.
		 *
		 * @spec openspec/changes/change-proposals-reach-the-case-page/specs/change-proposal-queue/spec.md#requirement-a-colleague-lists-their-own-proposals-on-a-record-req-cpc-002
		 */
		async loadMine() {
			const result = await this.queue.loadMine(this.host())
			this.mineState = result.state
			this.mine = result.proposals
		},

		/**
		 * @param {string} name A property, or `note`.
		 * @return {string} The input's id, unique to this record.
		 *
		 * @spec openspec/changes/change-proposals-reach-the-case-page/specs/change-proposal-queue/spec.md#requirement-a-colleague-who-may-read-a-record-proposes-from-the-queue-widget-req-cpc-001
		 */
		inputId(name) {
			return 'portaliq-propose-' + this.objectId + '-' + name
		},

		/**
		 * @param {string} state A proposal's state.
		 * @return {string} The state as the colleague reads it.
		 *
		 * @spec openspec/changes/change-proposals-reach-the-case-page/specs/change-proposal-queue/spec.md#requirement-a-colleague-who-may-read-a-record-proposes-from-the-queue-widget-req-cpc-001
		 */
		stateLabel(state) {
			const labels = {
				queued: t('portaliq', 'Waiting'),
				accepted: t('portaliq', 'Accepted'),
				rejected: t('portaliq', 'Rejected'),
				withdrawn: t('portaliq', 'Withdrawn'),
			}
			return labels[state] || String(state || '')
		},

		/**
		 * Send the changed fields as a proposal.
		 *
		 * @spec openspec/changes/change-proposals-reach-the-case-page/specs/change-proposal-queue/spec.md#requirement-a-colleague-who-may-read-a-record-proposes-from-the-queue-widget-req-cpc-001
		 */
		async propose() {
			this.busy = true
			const result = await this.queue.propose(this.host(), this.fields, this.values, this.note)
			this.busy = false
			this.noticeIsError = result.outcome !== 'proposed'
			if (result.outcome === 'nothingChanged') {
				this.notice = t('portaliq', 'Change a value before sending the proposal.')
				return
			}
			if (result.outcome === 'refused') {
				this.notice = t('portaliq', 'The proposal was refused.')
				return
			}
			this.notice = t('portaliq', 'Your proposal is sent for review.')
			this.resetValues()
			await this.loadMine()
		},

		/**
		 * Withdraw one of the colleague's own queued proposals.
		 *
		 * @param {object} proposal The proposal.
		 *
		 * @spec openspec/changes/change-proposals-reach-the-case-page/specs/change-proposal-queue/spec.md#requirement-a-colleague-who-may-read-a-record-proposes-from-the-queue-widget-req-cpc-001
		 */
		async withdraw(proposal) {
			this.busy = true
			const result = await this.queue.withdraw(proposal)
			this.busy = false
			this.noticeIsError = result.outcome !== 'withdrawn'
			this.notice = result.outcome === 'withdrawn'
				? t('portaliq', 'The proposal is withdrawn.')
				: t('portaliq', 'The proposal was refused.')
			if (result.outcome === 'withdrawn') {
				await this.loadMine()
			}
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

.portaliq-proposal-queue__state {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.portaliq-proposal-queue__state--error {
	color: var(--color-error);
}

.portaliq-proposal-queue__drift {
	border-inline-start: 4px solid var(--color-warning);
	padding-inline-start: 8px;
	margin: 4px 0;
}

.portaliq-proposal-queue__heading {
	font-size: 1em;
	font-weight: bold;
	margin: 8px 0 4px;
}

.portaliq-proposal-queue__field {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin-bottom: 8px;
}

.portaliq-proposal-queue__field input,
.portaliq-proposal-queue__field textarea {
	width: 100%;
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
