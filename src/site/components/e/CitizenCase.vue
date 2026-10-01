<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The citizen's own case (what-the-citizen-may-write-on-their-own-case,
	cases-documents-on-the-case, case-actions-withdraw-screen), ported from
	src/portal/components/CitizenCase.jsx. Also the `citizenCase` block inside
	slice b's contribution page: import it from src/site/components/e/index.js.

	Nothing on this screen decides what may be changed. The server answers with
	a writable set resolved from the case type, and this renders it: an open
	field is an input, a closed field is text with the sentence that says why.
	Documents are grouped decision first, then the organisation's documents,
	then what the citizen sent; each opens through the case route. A case
	listed under a mandate is read under that mandate.
-->
<template>
	<p v-if="!caseId" class="utrecht-paragraph pq-empty">
		<em>{{ t('Select a case.') }}</em>
	</p>
	<p
		v-else-if="loading"
		class="utrecht-paragraph"
		role="status"
		data-testid="case-loading">
		{{ t('Loading…') }}
	</p>
	<p
		v-else-if="!data"
		class="utrecht-paragraph pq-e-error"
		role="alert"
		data-testid="case-unavailable">
		{{ t('This case is not yours.') }}
	</p>
	<section v-else class="pq-citizen-case" data-testid="citizen-case">
		<div
			v-if="writableSet.status && writableSet.status.label"
			class="pq-case-status"
			data-testid="case-status">
			<h3 class="utrecht-heading-3">
				{{ writableSet.status.label }}
			</h3>
			<p v-if="writableSet.status.description" class="utrecht-paragraph">
				{{ writableSet.status.description }}
			</p>
		</div>

		<p
			v-if="!windowOpen"
			class="utrecht-paragraph pq-case-closed"
			data-testid="case-window-closed">
			{{ (writableSet.window && writableSet.window.reason) || '' }}
		</p>

		<div class="pq-case-fields">
			<CaseField
				v-for="field in fields"
				:key="field"
				:field="field"
				:state="fieldStates[field] || null"
				:value="draft[field] !== undefined ? draft[field] : caseRow[field]"
				:t="t"
				@change="onFieldChange" />
		</div>

		<button
			v-if="windowOpen"
			type="button"
			class="utrecht-button utrecht-button--primary-action pq-case-save"
			data-testid="case-save"
			:disabled="busy || Object.keys(draft).length === 0"
			@click="onSave">
			{{ t('Save my change') }}
		</button>

		<div class="pq-case-documents" data-testid="case-documents">
			<h4 class="utrecht-heading-4">
				{{ data.documentsLabel || t('Documents') }}
			</h4>
			<p
				v-if="groups.empty"
				class="utrecht-paragraph pq-empty"
				data-testid="case-documents-empty">
				{{ t('There are no documents on this case yet.') }}
			</p>
			<div
				v-for="group in documentGroups"
				:key="group.key"
				:class="`pq-case-documents-${group.key}`">
				<h5 class="utrecht-heading-5">
					{{ group.heading }}
				</h5>
				<ul class="utrecht-unordered-list">
					<li
						v-for="entry in group.entries"
						:key="entry.id"
						class="utrecht-unordered-list__item"
						data-testid="case-document">
						<button
							type="button"
							class="utrecht-button utrecht-button--subtle pq-case-document"
							@click="onOpenDocument(entry)">
							{{ entry.title }}
						</button>
						<span v-if="entry.date" class="pq-case-document-date">{{
							dateOf(entry.date)
						}}</span>
					</li>
				</ul>
			</div>
			<div v-if="documentsOpen" class="utrecht-form-field">
				<label for="pq-case-add-document" class="utrecht-form-label">{{
					t('Add a document')
				}}</label>
				<input
					id="pq-case-add-document"
					type="file"
					class="pq-case-add-document"
					data-testid="case-add-document"
					:disabled="busy"
					@change="onAddDocument" />
			</div>
			<p
				v-else
				class="utrecht-paragraph pq-case-reason"
				data-testid="case-documents-closed">
				{{ (writableSet.documents && writableSet.documents.reason) || '' }}
			</p>
		</div>

		<div
			v-if="withdrawal.kind === 'withdrawn'"
			class="pq-case-withdrawn"
			data-testid="case-withdrawn">
			<p class="utrecht-paragraph">
				{{
					t('Withdrawn on {date}.', {
						date: dateOf(withdrawal.withdrawnAt),
					})
				}}
			</p>
			<p v-if="withdrawal.reason" class="utrecht-paragraph">
				{{ t('Your reason: {reason}', { reason: withdrawal.reason }) }}
			</p>
		</div>

		<p
			v-if="withdrawal.kind === 'closed'"
			class="utrecht-paragraph pq-case-reason"
			data-testid="case-withdraw-closed">
			{{ withdrawal.reason }}
		</p>

		<button
			v-if="withdrawal.kind === 'button'"
			ref="withdrawButton"
			type="button"
			class="utrecht-button utrecht-button--secondary-action pq-case-withdraw"
			data-testid="case-withdraw"
			:disabled="busy"
			@click="openWithdraw">
			{{ t('Withdraw this request') }}
		</button>

		<WithdrawCaseConfirm
			v-if="withdrawal.kind === 'button' && confirming"
			:t="t"
			:confirmText="withdrawal.confirmText"
			:busy="busy"
			@confirm="onWithdraw"
			@cancel="closeWithdraw" />

		<p
			v-if="notice"
			class="utrecht-paragraph pq-case-notice"
			data-testid="case-notice"
			role="status">
			{{ notice }}
		</p>
	</section>
</template>

<script>
import WithdrawCaseConfirm from '../../modals/e/WithdrawCaseConfirm.vue'
import CaseField from './CaseField.vue'
import { groupDocuments } from '../../../shared/caseDocuments.js'
import { caseFieldNames, withdrawalView } from '../../../shared/withdrawal.js'
import { readerLocale, shortDate } from '../../pages/e/format.js'

export default {
	name: 'CitizenCase',

	components: { CaseField, WithdrawCaseConfirm },

	props: {
		/** The manifest collection the case lives in (`{id, register, schema, ...}`). */
		collection: { type: Object, required: true },
		/** The case row selected in the table; `_mandate.id` reads it under that mandate. */
		row: { type: Object, default: null },
		/** The portal API adapter (`createPortalApi` shape). */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
		/** The reader's locale; the page's `<html lang>` when empty. */
		locale: { type: String, default: '' },
		/** The server's case answer to show without fetching (test seam). */
		initialData: { type: Object, default: null },
		/** Open on the withdraw confirmation (test seam). */
		initialConfirming: { type: Boolean, default: false },
	},

	data() {
		return {
			loading: this.initialData === null,
			data: this.initialData,
			draft: {},
			notice: '',
			busy: false,
			confirming: this.initialConfirming,
		}
	},

	computed: {
		caseId() {
			return (this.row && (this.row.id || this.row['@self']?.id)) || ''
		},

		mandateId() {
			return this.row?._mandate?.id || ''
		},

		writableSet() {
			return this.data?.writableSet || {}
		},

		caseRow() {
			return this.data?.case || {}
		},

		fieldStates() {
			return this.writableSet.fields || {}
		},

		windowOpen() {
			return this.writableSet.window?.open === true
		},

		documentsOpen() {
			return this.writableSet.documents?.open === true
		},

		fields() {
			return caseFieldNames(this.caseRow)
		},

		withdrawal() {
			return withdrawalView(this.data?.withdrawal, this.caseRow)
		},

		groups() {
			return groupDocuments(this.data?.documents)
		},

		documentGroups() {
			return [
				{ key: 'decisions', heading: this.t('Decision') },
				{ key: 'documents', heading: this.t('Documents') },
				{ key: 'yours', heading: this.t('Sent by you') },
			]
				.map((group) => ({ ...group, entries: this.groups[group.key] }))
				.filter((group) => group.entries.length > 0)
		},
	},

	watch: {
		caseId() {
			this.load()
		},

		mandateId() {
			this.load()
		},
	},

	mounted() {
		if (this.initialData === null) {
			this.load()
		}
	},

	methods: {
		/**
		 * Read the case, its writable set, documents and withdrawal.
		 *
		 * @return {Promise<void>} Resolves when read.
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-citizen-must-work-on-their-own-case-req-srp-042
		 */
		async load() {
			if (!this.caseId) {
				return
			}
			this.loading = true
			this.data = null
			const id = this.caseId
			const data = await this.api.fetchCitizenCase(
				this.collection,
				id,
				this.mandateId,
			)
			if (id !== this.caseId) {
				return
			}
			this.data = data
			this.loading = false
			this.draft = {}
		},

		/**
		 * A date in the reader's language.
		 *
		 * @param {string} value An ISO date.
		 * @return {string} The date.
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-citizen-must-work-on-their-own-case-req-srp-042
		 */
		dateOf(value) {
			return shortDate(value, readerLocale(this.locale))
		},

		/**
		 * Open one listed document; a failure says so.
		 *
		 * @param {object} entry The listed entry.
		 * @return {Promise<void>} Resolves when answered.
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-citizen-must-work-on-their-own-case-req-srp-042
		 */
		async onOpenDocument(entry) {
			const result = await this.api.downloadCitizenDocument(
				this.collection,
				this.caseId,
				entry,
			)
			if (!result.ok) {
				this.notice = this.t(
					'The document could not be opened. Try again later.',
				)
			}
		},

		/**
		 * Hold one corrected answer until the citizen saves.
		 *
		 * @param {string} field The field name.
		 * @param {string} value The new answer.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-citizen-must-work-on-their-own-case-req-srp-042
		 */
		onFieldChange(field, value) {
			this.draft = { ...this.draft, [field]: value }
		},

		/**
		 * Send the corrections. A refusal shows the sentence the server gave.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-citizen-must-work-on-their-own-case-req-srp-042
		 */
		async onSave() {
			if (Object.keys(this.draft).length === 0) {
				return
			}
			this.busy = true
			const result = await this.api.amendCitizenCase(
				this.collection,
				this.caseId,
				this.draft,
			)
			this.busy = false
			this.notice = result.ok
				? this.t('Your change has been saved.')
				: result.message
					|| this.t('The change could not be saved. Please try again.')
			if (result.ok) {
				this.load()
			}
		},

		/**
		 * Add a document to the case.
		 *
		 * @param {Event} event The file input's change event.
		 * @return {Promise<void>} Resolves when answered.
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-citizen-must-work-on-their-own-case-req-srp-042
		 */
		async onAddDocument(event) {
			const file = event.target.files && event.target.files[0]
			if (!file) {
				return
			}
			this.busy = true
			const result = await this.api.addCitizenDocument(
				this.collection,
				this.caseId,
				file,
			)
			this.busy = false
			event.target.value = ''
			this.notice = result.ok
				? this.t('{name} has been added to your case.', {
						name: result.document?.name || file.name,
					})
				: result.message
					|| this.t('The document could not be added. Please try again.')
			if (result.ok) {
				this.load()
			}
		},

		/**
		 * Open the withdraw confirmation.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-citizen-must-be-able-to-withdraw-a-request-req-srp-043
		 */
		openWithdraw() {
			this.notice = ''
			this.confirming = true
		},

		/**
		 * Close the confirmation without sending, focus back on the withdraw button.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-citizen-must-be-able-to-withdraw-a-request-req-srp-043
		 */
		closeWithdraw() {
			this.confirming = false
			this.$nextTick(() => this.$refs.withdrawButton?.focus())
		},

		/**
		 * Withdraw the request, once the resident confirmed. A refusal is the
		 * server's sentence, as for a save.
		 *
		 * @param {string} reason Why, or ''.
		 * @return {Promise<void>} Resolves when answered.
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-citizen-must-be-able-to-withdraw-a-request-req-srp-043
		 */
		async onWithdraw(reason) {
			this.busy = true
			const result = await this.api.withdrawCitizenCase(
				this.collection,
				this.caseId,
				reason,
			)
			this.busy = false
			this.confirming = false
			this.notice = result.ok
				? this.t('Your request has been withdrawn.')
				: result.message
					|| this.t('The change could not be saved. Please try again.')
			if (result.ok) {
				this.load()
			}
		},
	},
}
</script>

<style scoped>
.pq-citizen-case > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-case-reason,
.pq-case-closed {
	font-style: italic;
}

.pq-e-error {
	color: var(
		--utrecht-feedback-danger-color,
		var(--nldesign-color-error, currentcolor)
	);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}
</style>
