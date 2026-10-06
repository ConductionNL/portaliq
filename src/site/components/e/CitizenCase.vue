<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The citizen's own case (what-the-citizen-may-write-on-their-own-case,
	cases-documents-on-the-case, case-actions-withdraw-screen), ported from
	the React portal's CitizenCase.jsx. Also the `citizenCase` block inside
	slice b's contribution page: import it from src/site/components/e/index.js.

	Nothing on this screen decides what may be changed. The server answers with
	a writable set resolved from the case type, and this renders it: an open
	field is an input, a closed field is text with the sentence that says why.
	Documents are grouped decision first, then the organisation's documents,
	then what the citizen sent; each opens through the case route. A case
	listed under a mandate is read under that mandate.
-->
<template>
	<template v-if="!caseId">
		<!-- Under a detail card on the same collection, the card says it. -->
		<p v-if="!quietWhenEmpty" class="utrecht-paragraph pq-empty">
			<em>{{ t('Select a case.') }}</em>
		</p>
	</template>
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
	<section
		v-else
		class="pq-citizen-case"
		:class="{ 'pq-citizen-case--actions': actionsOnly }"
		data-testid="citizen-case">
		<!-- With `display: actions` the status and the documents are other
		     blocks' (zuiddrecht-resident-pages-match-the-boards): this screen
		     keeps the closed window sentence as a notice, the fields, save
		     and withdraw. -->
		<div
			v-if="!actionsOnly && writableSet.status && writableSet.status.label"
			class="pq-case-status"
			data-testid="case-status">
			<h3 class="utrecht-heading-3">
				{{ writableSet.status.label }}
			</h3>
			<p v-if="writableSet.status.description" class="utrecht-paragraph">
				{{ writableSet.status.description }}
			</p>
		</div>

		<div
			v-if="actionsOnly && !windowOpen && !ended && windowReason"
			class="utrecht-alert utrecht-alert--ok pq-case-ok"
			role="status"
			data-testid="case-window-closed">
			<svg
				class="pq-case-ok__icon"
				viewBox="0 0 24 24"
				aria-hidden="true"
				focusable="false">
				<path
					d="M5 12.5l4.5 4.5L19 7"
					fill="none"
					stroke="currentColor"
					stroke-width="2.4"
					stroke-linecap="round"
					stroke-linejoin="round" />
			</svg>
			<p class="utrecht-paragraph pq-case-ok__text">{{ windowReason }}</p>
		</div>
		<p
			v-else-if="!windowOpen && !ended"
			class="utrecht-paragraph pq-case-closed"
			data-testid="case-window-closed">
			{{ windowReason }}
		</p>

		<div class="pq-case-fields">
			<CaseField
				v-for="field in fields"
				:key="field"
				:field="field"
				:state="fieldStates[field] || null"
				:value="draft[field] !== undefined ? draft[field] : caseRow[field]"
				:quiet="ended"
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

		<div
			v-if="!actionsOnly"
			class="pq-case-documents"
			data-testid="case-documents">
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
				<!-- Each document a Den Haag file item (site-mijn-omgeving-components
				     REQ-SMO-005): its name, then who added it, when, type and size. -->
				<ul class="pq-case-documents__list">
					<FileItem
						v-for="entry in group.entries"
						:key="entry.id"
						data-testid="case-document"
						:name="entry.title || entry.id"
						:line="fileLineOf(entry)"
						@open="onOpenDocument(entry)" />
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
				v-else-if="!ended"
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
			v-if="withdrawal.kind === 'closed' && !ended"
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
import FileItem from '../mijn/FileItem.vue'
import CaseField from './CaseField.vue'
import { groupDocuments } from '../../../shared/caseDocuments.js'
import {
	caseFieldNames,
	caseHasEnded,
	withdrawalView,
} from '../../../shared/withdrawal.js'
import { readerLocale, shortDate } from '../../pages/e/format.js'
import { fileLine } from '../mijn/documents.js'
import { mijnTranslator } from '../mijn/rows.js'

export default {
	name: 'CitizenCase',

	components: { CaseField, FileItem, WithdrawCaseConfirm },

	props: {
		/** The manifest collection the case lives in (`{id, register, schema, ...}`). */
		collection: { type: Object, required: true },
		/** The page block (`display: actions` keeps only the actions). */
		block: { type: Object, default: null },
		/** The case row selected in the table; `_mandate.id` reads it under that mandate. */
		row: { type: Object, default: null },
		/** Say nothing until a case is chosen: a detail card on the page already asks. */
		quietWhenEmpty: { type: Boolean, default: false },
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

		/**
		 * @return {string} Why the window is shut, in the server's words.
		 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
		 */
		windowReason() {
			return (this.writableSet.window && this.writableSet.window.reason) || ''
		},

		/**
		 * Whether this screen keeps only the actions: the status and the
		 * documents are other blocks' on the page.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
		 */
		actionsOnly() {
			return this.block?.display === 'actions'
		},

		documentsOpen() {
			return this.writableSet.documents?.open === true
		},

		fields() {
			return caseFieldNames(this.caseRow, this.writableSet)
		},

		withdrawal() {
			return withdrawalView(this.data?.withdrawal, this.caseRow)
		},

		/** Withdrawn or closed: the screen shows the state, not why a window shut. */
		ended() {
			return caseHasEnded(this.writableSet, this.withdrawal)
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
		 * The line under a document's name: who added it, when, its type and size.
		 *
		 * @param {object} entry The listed document.
		 * @return {string} The line.
		 *
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		fileLineOf(entry) {
			return fileLine(
				entry,
				mijnTranslator(this.t, readerLocale(this.locale)),
				readerLocale(this.locale),
			)
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

/* The closed window as a notice in the ok tone: a tick and one sentence. */
.pq-case-ok {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	padding: 1rem 1.25rem;
	border: 1px solid
		var(
			--utrecht-alert-ok-border-color,
			var(--nldesign-color-success, currentcolor)
		);
	border-radius: var(--utrecht-alert-border-radius, 0.25rem);
	background-color: var(
		--utrecht-alert-ok-background-color,
		var(
			--nldesign-component-status-badge-success-background-color,
			rgba(var(--nldesign-color-success-rgb, 57, 135, 12), 0.12)
		)
	);
	color: var(--utrecht-alert-ok-color, inherit);
}

.pq-case-ok__icon {
	flex: none;
	inline-size: 1.375rem;
	block-size: 1.375rem;
	color: var(
		--utrecht-alert-icon-ok-color,
		var(--nldesign-color-success, currentcolor)
	);
}

.pq-case-ok__text {
	margin: 0;
	font-size: 1.125rem;
}
</style>
