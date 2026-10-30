<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"Actie bewerken" (personal-action-list, board ActieBewerken): title,
	description, kind, status, end date, who does it, one file and the
	history. Opened from Mijn acties and from a plan. The server decides what
	the viewer may change; an assignee who is not the owner sees the fields
	they may not change as read-only and no delete.
-->
<template>
	<dialog
		ref="dialog"
		class="pq-action-edit"
		aria-labelledby="pq-action-edit-title"
		data-testid="action-edit"
		@cancel.prevent="$emit('cancel')">
		<form method="dialog" novalidate @submit.prevent="submit">
			<div class="pq-action-edit__head">
				<h2
					id="pq-action-edit-title"
					ref="heading"
					class="utrecht-heading-3"
					tabindex="-1">
					{{ isNew ? words.newAction : words.editAction }}
				</h2>
				<button
					type="button"
					class="utrecht-button utrecht-button--subtle pq-action-edit__close"
					:aria-label="words.close"
					data-testid="action-edit-close"
					@click="$emit('cancel')">
					<span aria-hidden="true">×</span>
				</button>
			</div>
			<p v-if="lead" class="utrecht-paragraph" data-testid="action-edit-lead">
				{{ lead }}
			</p>

			<div class="utrecht-form-field">
				<label for="pq-action-title" class="utrecht-form-label">{{
					words.fieldTitle
				}}</label>
				<input
					id="pq-action-title"
					v-model="draft.title"
					class="utrecht-textbox"
					type="text"
					maxlength="200"
					:readonly="limited"
					:aria-invalid="titleMissing ? 'true' : undefined"
					data-testid="action-edit-title" />
			</div>
			<div class="utrecht-form-field">
				<label for="pq-action-description" class="utrecht-form-label">
					{{ words.fieldDescription }}
					<span class="pq-action-edit__optional">{{
						words.optional
					}}</span>
				</label>
				<textarea
					id="pq-action-description"
					v-model="draft.description"
					class="utrecht-textarea"
					rows="3"
					maxlength="2000"
					data-testid="action-edit-description" />
			</div>
			<fieldset class="utrecht-form-fieldset" :disabled="limited">
				<legend class="utrecht-form-label">
					{{ words.kind }}
				</legend>
				<div class="pq-action-edit__choices">
					<label
						v-for="kind in ['once', 'recurring']"
						:key="kind"
						class="pq-action-edit__choice">
						<input
							v-model="draft.kind"
							type="radio"
							name="pq-action-kind"
							:value="kind"
							:data-testid="`action-edit-kind-${kind}`" />
						{{ words[kind] }}
					</label>
				</div>
			</fieldset>
			<fieldset class="utrecht-form-fieldset">
				<legend class="utrecht-form-label">
					{{ words.status }}
				</legend>
				<div class="pq-action-edit__choices">
					<label
						v-for="status in ['todo', 'doing', 'done']"
						:key="status"
						class="pq-action-edit__choice">
						<input
							v-model="draft.status"
							type="radio"
							name="pq-action-status"
							:value="status"
							:data-testid="`action-edit-status-${status}`" />
						{{ words[status] }}
					</label>
				</div>
			</fieldset>
			<div class="pq-action-edit__pair">
				<div class="utrecht-form-field">
					<label for="pq-action-end" class="utrecht-form-label">{{
						words.endDate
					}}</label>
					<input
						id="pq-action-end"
						v-model="draft.endDate"
						class="utrecht-textbox"
						type="date"
						:readonly="limited"
						data-testid="action-edit-end" />
				</div>
				<div class="utrecht-form-field">
					<label for="pq-action-assignee" class="utrecht-form-label">{{
						words.assignee
					}}</label>
					<select
						id="pq-action-assignee"
						v-model="draft.assignee"
						class="utrecht-select"
						:disabled="limited"
						data-testid="action-edit-assignee">
						<option
							v-for="person in people"
							:key="person.ref"
							:value="person.ref">
							{{ personName(person) }}
						</option>
					</select>
				</div>
			</div>

			<section class="pq-action-edit__file" aria-labelledby="pq-action-file">
				<h3 id="pq-action-file" class="utrecht-form-label">
					{{ words.file }}
				</h3>
				<input
					ref="fileInput"
					type="file"
					accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
					class="pq-action-edit__file-input"
					tabindex="-1"
					aria-hidden="true"
					data-testid="action-edit-file-input"
					@change="pickFile" />
				<p v-if="isNew" class="utrecht-paragraph">
					{{ words.fileAfterSave }}
				</p>
				<div
					v-else-if="fileName"
					class="pq-action-edit__file-box"
					data-testid="action-edit-file">
					<button
						type="button"
						class="utrecht-link utrecht-link--button"
						data-testid="action-edit-file-open"
						@click="openFile">
						{{ fileName }}
					</button>
					<span class="pq-action-edit__file-actions">
						<button
							type="button"
							class="utrecht-link utrecht-link--button"
							data-testid="action-edit-file-replace"
							@click="chooseFile">
							{{ words.replace }}
						</button>
						<button
							type="button"
							class="utrecht-link utrecht-link--button pq-action-edit__danger"
							data-testid="action-edit-file-remove"
							@click="removeFile">
							{{ words.removeFile }}
						</button>
					</span>
				</div>
				<button
					v-else
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					data-testid="action-edit-file-add"
					@click="chooseFile">
					{{ words.addFile }}
				</button>
				<p
					class="utrecht-form-field-description"
					:class="{ 'pq-action-edit__error': fileRefused }"
					:role="fileRefused ? 'alert' : undefined"
					data-testid="action-edit-file-rule">
					{{ words.fileRule }}
				</p>
			</section>

			<details v-if="!isNew" class="pq-action-edit__history" open>
				<summary data-testid="action-edit-history-toggle">
					{{ words.history }}
				</summary>
				<p v-if="history.length === 0" class="utrecht-paragraph">
					{{ words.noHistory }}
				</p>
				<table
					v-else
					class="utrecht-table"
					data-testid="action-edit-history">
					<caption class="pq-action-edit__sr">
						{{
							words.history
						}}
					</caption>
					<thead class="pq-action-edit__sr">
						<tr>
							<th scope="col">
								{{ words.when }}
							</th>
							<th scope="col">
								{{ words.change }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(line, index) in history" :key="index">
							<td class="pq-action-edit__when">
								{{ momentText(line.at, locale || pageLocale) }}
							</td>
							<td>
								<strong>{{ line.who || words.someone }}</strong
								>:
								{{ historyText(line, words, locale || pageLocale) }}
							</td>
						</tr>
					</tbody>
				</table>
			</details>

			<p
				v-if="problem !== ''"
				class="utrecht-paragraph pq-action-edit__error"
				role="alert"
				data-testid="action-edit-problem">
				{{ problem }}
			</p>
			<div class="pq-action-edit__buttons">
				<template v-if="!isNew && action.mayDelete">
					<button
						v-if="!confirming"
						type="button"
						class="utrecht-link utrecht-link--button pq-action-edit__danger"
						data-testid="action-edit-delete"
						@click="confirming = true">
						{{ words.deleteAction }}
					</button>
					<span v-else class="pq-action-edit__confirm">
						<span>{{ words.sure }}</span>
						<button
							type="button"
							class="utrecht-button utrecht-button--primary-action"
							data-testid="action-edit-delete-confirm"
							@click="destroy">
							{{ words.deleteAction }}
						</button>
					</span>
				</template>
				<span class="pq-action-edit__spacer" />
				<button
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					data-testid="action-edit-cancel"
					@click="$emit('cancel')">
					{{ words.cancel }}
				</button>
				<button
					type="submit"
					class="utrecht-button utrecht-button--primary-action"
					:disabled="busy"
					data-testid="action-edit-save">
					{{ words.save }}
				</button>
			</div>
		</form>
	</dialog>
</template>

<script>
import { actionsApi, plansApi } from '../../../shared/areaApi.js'
import {
	actionWords,
	fileAllowed,
	historyText,
	leadText,
	momentText,
} from '../../lib/actions.js'

/**
 * @spec openspec/changes/personal-action-list/tasks.md#t03
 */
export default {
	name: 'ActionEditModal',

	props: {
		/** The portal api. */
		api: { type: Object, required: true },
		/** The action to edit, or null to add one. */
		action: { type: Object, default: null },
		/** Who a new action may be given to; an existing one carries its own. */
		assignees: { type: Array, default: () => [] },
		/** The plan the action is in, or ''. */
		planId: { type: String, default: '' },
		/** The plan's name, for the lead of a new action in a plan. */
		planTitle: { type: String, default: '' },
		/** The viewer's subject reference. */
		viewer: { type: String, default: '' },
		/** The language, `nl` or `en`; the page's when empty. */
		locale: { type: String, default: '' },
	},

	emits: ['saved', 'deleted', 'cancel'],

	data() {
		const action = this.action || {}
		return {
			draft: {
				title: action.title || '',
				description: action.description || '',
				kind: action.kind || 'once',
				status: action.status || 'todo',
				endDate: action.endDate || '',
				assignee: action.assignee || this.viewer,
			},

			fileName: action.fileName || '',
			history: [],
			problem: '',
			fileRefused: false,
			titleMissing: false,
			confirming: false,
			busy: false,
		}
	},

	computed: {
		/**
		 * @return {string} The page's language.
		 * @spec openspec/changes/personal-action-list/tasks.md#t03
		 */
		pageLocale() {
			return globalThis.document?.documentElement?.lang || ''
		},

		/**
		 * @return {Record<string, string>} The words in the page language.
		 * @spec openspec/changes/personal-action-list/tasks.md#t03
		 */
		words() {
			return actionWords(this.locale || this.pageLocale)
		},

		/**
		 * @return {boolean} Whether this adds an action.
		 * @spec openspec/changes/personal-action-list/tasks.md#t03
		 */
		isNew() {
			return !this.action?.id
		},

		/**
		 * @return {boolean} Whether the viewer may change only status, description and file.
		 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-an-action-can-be-shared-with-an-approved-contact-req-ral-002
		 */
		limited() {
			return this.action?.role === 'assignee'
		},

		/**
		 * @return {Array<{ref: string, displayName: string}>} Who the action may be given to.
		 * @spec openspec/changes/personal-action-list/tasks.md#t03
		 */
		people() {
			const own = this.action?.assignees
			const list = Array.isArray(own) && own.length > 0 ? own : this.assignees
			if (list.some((person) => person.ref === this.draft.assignee)) {
				return list
			}
			return [
				...list,
				{
					ref: this.draft.assignee,
					displayName: this.action?.assigneeName || '',
				},
			]
		},

		/**
		 * @return {string} In which plan, and who else sees the changes.
		 * @spec openspec/changes/personal-action-list/tasks.md#t03
		 */
		lead() {
			const action = this.action || { planTitle: this.planTitle }
			return leadText(action, this.people, this.viewer, this.words)
		},
	},

	/**
	 * Open as a modal and read the history.
	 *
	 * @return {Promise<void>} Resolves when read.
	 * @spec openspec/changes/personal-action-list/tasks.md#t03
	 */
	async mounted() {
		const dialog = this.$refs.dialog
		if (typeof dialog?.showModal === 'function') {
			dialog.showModal()
		} else {
			dialog?.setAttribute('open', '')
		}
		this.$refs.heading?.focus()
		await this.loadHistory()
	},

	/**
	 * Close the native dialog when the component goes.
	 *
	 * @return {void}
	 * @spec openspec/changes/personal-action-list/tasks.md#t03
	 */
	beforeUnmount() {
		if (
			this.$refs.dialog?.open
			&& typeof this.$refs.dialog.close === 'function'
		) {
			this.$refs.dialog.close()
		}
	},

	methods: {
		historyText,
		momentText,

		/**
		 * @param {{ref: string, displayName: string}} person Someone who may be assigned.
		 * @return {string} "Sanne de Vries (u)" for the viewer.
		 * @spec openspec/changes/personal-action-list/tasks.md#t03
		 */
		personName(person) {
			return person.ref === this.viewer
				? `${person.displayName} (${this.words.you})`
				: person.displayName
		},

		/**
		 * @return {Promise<void>} Resolves when read.
		 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-an-action-can-be-shared-with-an-approved-contact-req-ral-002
		 */
		async loadHistory() {
			if (this.isNew) {
				return
			}
			const lines = await actionsApi(this.api).fetchHistory(
				this.action.id,
				this.planId,
			)
			this.history = lines || []
		},

		/**
		 * The fields the viewer may send.
		 *
		 * @return {object} The fields.
		 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-an-action-can-be-shared-with-an-approved-contact-req-ral-002
		 */
		fields() {
			const draft = { ...this.draft, title: this.draft.title.trim() }
			if (this.limited) {
				return { status: draft.status, description: draft.description }
			}
			if (draft.endDate === '') {
				delete draft.endDate
			}
			return draft
		},

		/**
		 * Save the action. A refusal is told in words and the dialog stays open.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-a-resident-keeps-a-list-of-own-actions-req-ral-001
		 */
		async submit() {
			this.problem = ''
			this.titleMissing = !this.limited && this.draft.title.trim() === ''
			if (this.titleMissing) {
				this.problem = this.words.titleNeeded
				return
			}
			this.busy = true
			const answer = await this.send(this.fields())
			this.busy = false
			if (!answer.ok) {
				this.problem = this.words.failed
				return
			}
			this.$emit('saved')
		},

		/**
		 * Send the fields to the right place: a new action in a plan through
		 * the plan, every other write through the actions.
		 *
		 * @param {object} data The fields.
		 * @return {Promise<{ok: boolean}>} The outcome.
		 * @spec openspec/changes/personal-action-list/tasks.md#t03
		 */
		send(data) {
			if (!this.isNew) {
				return actionsApi(this.api).updateAction(
					this.action.id,
					data,
					this.planId,
				)
			}
			if (this.planId) {
				return plansApi(this.api).planAction('addAction', {
					id: this.planId,
					data,
				})
			}
			return actionsApi(this.api).createAction(data)
		},

		/**
		 * @return {void}
		 * @spec openspec/changes/personal-action-list/tasks.md#t03
		 */
		chooseFile() {
			this.$refs.fileInput?.click()
		},

		/**
		 * Keep the picked file with the action, or say why not.
		 *
		 * @param {Event} event The picker's change.
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-a-resident-keeps-a-list-of-own-actions-req-ral-001
		 */
		async pickFile(event) {
			const input = event?.target
			const file = input?.files?.[0]
			if (input) {
				input.value = ''
			}
			if (!file) {
				return
			}
			this.problem = ''
			this.fileRefused = !fileAllowed(file)
			if (this.fileRefused) {
				return
			}
			const answer = await actionsApi(this.api).attachActionFile(
				this.action.id,
				file,
				this.planId,
			)
			if (!answer.ok) {
				this.fileRefused = answer.status === 400
				this.problem = this.fileRefused ? '' : this.words.failed
				return
			}
			this.fileName = file.name
			await this.loadHistory()
		},

		/**
		 * Take the file off the action.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/personal-action-list/tasks.md#t03
		 */
		async removeFile() {
			this.problem = ''
			const answer = await actionsApi(this.api).updateAction(
				this.action.id,
				{ file: '' },
				this.planId,
			)
			if (!answer.ok) {
				this.problem = this.words.failed
				return
			}
			this.fileName = ''
			await this.loadHistory()
		},

		/**
		 * Save the action's file.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/personal-action-list/tasks.md#t03
		 */
		async openFile() {
			const answer = await actionsApi(this.api).downloadActionFile(
				this.action.id,
				this.fileName,
				this.planId,
			)
			if (!answer.ok) {
				this.problem = this.words.failed
			}
		},

		/**
		 * Delete the action.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-an-action-can-be-shared-with-an-approved-contact-req-ral-002
		 */
		async destroy() {
			const answer = await actionsApi(this.api).deleteAction(
				this.action.id,
				this.planId,
			)
			if (!answer.ok) {
				this.problem = this.words.failed
				this.confirming = false
				return
			}
			this.$emit('deleted')
		},
	},
}
</script>

<style scoped>
.pq-action-edit {
	inline-size: min(40rem, calc(100vw - 2rem));
	max-block-size: calc(100vh - 2rem);
	padding: var(--utrecht-space-block-lg, 1.5rem);
	color: var(--utrecht-document-color, inherit);
	background: var(--utrecht-document-background-color, Canvas);
	border: var(--utrecht-border-width-sm, 1px) solid
		var(--utrecht-color-grey-80, currentcolor);
	border-radius: var(--utrecht-border-radius-md, 0.5rem);
}

.pq-action-edit form > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-action-edit__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-action-edit__close {
	font-size: 1.5rem;
	line-height: 1;
}

.pq-action-edit__optional {
	font-weight: normal;
}

.pq-action-edit input[type='text'],
.pq-action-edit textarea,
.pq-action-edit select,
.pq-action-edit input[type='date'] {
	inline-size: 100%;
}

.pq-action-edit__choices {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-action-edit__choice {
	display: inline-flex;
	align-items: center;
	gap: var(--utrecht-space-inline-xs, 0.25rem);
	padding-block: var(--utrecht-space-block-xs, 0.5rem);
	padding-inline: var(--utrecht-space-inline-md, 0.75rem);
	border: var(--utrecht-border-width-sm, 1px) solid
		var(--utrecht-color-grey-80, currentcolor);
	border-radius: var(--utrecht-border-radius-sm, 0.25rem);
}

.pq-action-edit__choice:has(input:checked) {
	border-color: var(--utrecht-focus-outline-color, currentcolor);
	border-width: 2px;
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}

.pq-action-edit__pair {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
	gap: var(--utrecht-space-inline-md, 1rem);
}

.pq-action-edit__file-input {
	display: none;
}

.pq-action-edit__file-box {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	padding: var(--utrecht-space-block-sm, 0.75rem);
	border: var(--utrecht-border-width-sm, 1px) solid
		var(--utrecht-color-grey-90, currentcolor);
}

.pq-action-edit__file-actions {
	display: inline-flex;
	gap: var(--utrecht-space-inline-md, 1rem);
}

.pq-action-edit__danger {
	color: var(--utrecht-feedback-danger-color, currentcolor);
}

.pq-action-edit__error {
	color: var(--utrecht-feedback-danger-color, currentcolor);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}

.pq-action-edit__history summary {
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
	cursor: pointer;
}

.pq-action-edit__when {
	white-space: nowrap;
	vertical-align: top;
}

.pq-action-edit__sr {
	position: absolute;
	inline-size: 1px;
	block-size: 1px;
	overflow: hidden;
	clip-path: inset(50%);
}

.pq-action-edit__buttons {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	padding-block-start: var(--utrecht-space-block-md, 1rem);
	border-block-start: var(--utrecht-border-width-sm, 1px) solid
		var(--utrecht-color-grey-90, currentcolor);
}

.pq-action-edit__spacer {
	flex: 1;
}

.pq-action-edit__confirm {
	display: inline-flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}
</style>
