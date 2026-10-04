<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div
		v-if="actions.length > 0 && row && api"
		class="pq-save pq-attached"
		data-testid="attached-actions">
		<template v-if="open === null">
			<button
				v-for="entry in actions"
				:key="`${entry.app}:${entry.id}`"
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				:data-testid="`attached-action-${entry.id}`"
				@click="choose(entry)">
				{{ entry.label || entry.id }}
			</button>
		</template>

		<div
			v-else-if="inSteps"
			class="pq-save__form"
			data-testid="attached-action-steps">
			<h3 class="utrecht-heading-3">
				{{ open.label || open.id }}
			</h3>
			<SchemaForm
				:action="open"
				:api="api"
				:t="t"
				:send="sendOpen"
				@submitted="afterSteps" />
			<button
				type="button"
				class="utrecht-button utrecht-button--subtle"
				@click="open = null">
				{{ translate('Cancel') }}
			</button>
		</div>

		<form
			v-else
			class="pq-save__form"
			:aria-label="open.label || open.id"
			novalidate
			@submit.prevent="submit">
			<h3 class="utrecht-heading-3">
				{{ open.label || open.id }}
			</h3>
			<ErrorSummary
				ref="summary"
				:entries="summary"
				:idBase="`attached-${open.id}-summary`"
				:heading="translate('Something is still missing')"
				:intro="translate('Fill this in. Then you can continue.')"
				:titlePrefix="translate('Error: ')" />
			<SchemaField
				v-for="field in fields"
				:id="`attached-${open.id}-${field}`"
				:key="field"
				v-model="values[field]"
				:field="field"
				:label="labelOf(field)"
				:config="configOf(field)"
				input="textarea"
				:error="errors[field] || ''"
				:t="t" />
			<div class="pq-save__buttons">
				<button
					type="submit"
					class="utrecht-button utrecht-button--primary-action"
					:disabled="busy">
					{{
						busy
							? translate('Please wait…')
							: open.submitLabel || translate('Send')
					}}
				</button>
				<button
					type="button"
					class="utrecht-button utrecht-button--subtle"
					@click="open = null">
					{{ translate('Cancel') }}
				</button>
			</div>
		</form>

		<p
			class="utrecht-paragraph pq-save__status"
			role="status"
			data-testid="attached-actions-status">
			{{ message }}
		</p>
	</div>
</template>

<script>
import ErrorSummary from '../forms/ErrorSummary.vue'
import SchemaField from './SchemaField.vue'
import SchemaForm from './SchemaForm.vue'
import {
	attachedActionsOf,
	fieldLabel,
	runAttachedAction,
} from '../../../shared/attachedActions.js'
import { summaryEntries } from '../forms/fields.js'
import {
	fieldConfig,
	fieldErrors,
	formFields,
	serverFieldErrors,
	translatorOr,
} from './forms.js'

/**
 * Another app's actions on this record (the Vue port of AttachedActions.jsx):
 * on a dossier, pipelinq's question and dossiq's Woo request. One button per
 * action, in the same shape as the site's save buttons (SaveToDossier.vue,
 * SaveSearch.vue). Choosing one opens its fields; sending forwards them with
 * the record's id through the row-action route with `actionApp`, so the
 * server proves the record is the resident's first.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-another-apps-actions-must-show-on-a-record-req-srp-028
 */
export default {
	name: 'AttachedActions',

	components: { ErrorSummary, SchemaField, SchemaForm },

	inheritAttrs: false,

	props: {
		/** The collection the record belongs to (lists `attachedActions`). */
		collection: { type: Object, required: true },
		/** The record on screen. */
		row: { type: Object, default: null },
		/** The portal api (`forwardRowAction`). */
		api: { type: Object, default: null },
		/** The translator `t(key, vars)`. */
		t: { type: Function, default: null },
	},

	data() {
		return {
			open: null,
			values: {},
			errors: {},
			busy: false,
			message: '',
		}
	},

	computed: {
		translate() {
			return translatorOr(this.t)
		},

		/**
		 * The actions that apply to the record on screen: an action with
		 * `rowWhen` (pipelinq's reply, only on a question that waits for the
		 * resident) shows only on the rows it names, as the portal did.
		 *
		 * @return {Array<object>} The actions.
		 *
		 * @spec openspec/changes/attach-to-own-collection/specs/portal-contribution-contract/spec.md#requirement-an-attached-action-must-carry-its-rowwhen-to-the-renderer-req-ato-002
		 */
		actions() {
			return this.row ? attachedActionsOf(this.collection, this.row) : []
		},

		fields() {
			return this.open ? formFields(this.open) : []
		},

		/**
		 * Whether the open action runs in steps, through the action form's step
		 * flow (site-multi-step-forms REQ-SMF-020): dossiq's Woo request.
		 *
		 * @return {boolean} True with steps.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-create-or-endpoint-action-may-run-in-steps-with-a-review-a-draft-and-a-confirmation-req-smf-020
		 */
		inSteps() {
			return (
				this.open !== null
				&& Array.isArray(this.open.steps)
				&& this.open.steps.length > 0
			)
		},

		/**
		 * The error summary's lines, in field order.
		 *
		 * @return {Array<{field: string, target: string, message: string}>} The lines.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
		 */
		summary() {
			return this.open
				? summaryEntries(
						this.fields,
						this.errors,
						(field) => `attached-${this.open.id}-${field}`,
					)
				: []
		},
	},

	methods: {
		labelOf(field) {
			return fieldLabel(this.open, field)
		},

		configOf(field) {
			return fieldConfig(this.open, field)
		},

		/**
		 * Forward the stepped form's body for the record on screen, in the
		 * result shape the action form reads.
		 *
		 * @param {object} body What the resident answered.
		 * @return {Promise<{ok: boolean, object: object, errors: object}>} The result.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-create-or-endpoint-action-may-run-in-steps-with-a-review-a-draft-and-a-confirmation-req-smf-020
		 */
		async sendOpen(body) {
			const result = await runAttachedAction(
				this.api,
				this.collection,
				this.row,
				this.open,
				body,
			)
			return {
				ok: result.ok,
				object: result.body,
				errors: (result.body && result.body.errors) || {},
			}
		},

		/**
		 * After a stepped send: the action's own confirmation stays on screen;
		 * without one the success line shows and the form closes.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-create-or-endpoint-action-may-run-in-steps-with-a-review-a-draft-and-a-confirmation-req-smf-020
		 */
		afterSteps() {
			if (this.open && this.open.confirmation) {
				return
			}
			const success = this.open ? this.open.successMessage : ''
			this.message =
				typeof success === 'string' && success !== ''
					? success
					: this.translate('Done.')
			this.open = null
		},

		/**
		 * Move focus to the error summary's heading.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
		 */
		focusSummary() {
			if (this.$refs.summary) {
				this.$refs.summary.focus()
			}
		},

		/**
		 * Open one action's fields.
		 *
		 * @param {object} entry The attached action.
		 */
		choose(entry) {
			this.open = entry
			this.values = Object.fromEntries(
				formFields(entry).map((field) => [field, '']),
			)
			this.errors = {}
			this.message = ''
		},

		/**
		 * Forward the open action with the record's id.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-another-apps-actions-must-show-on-a-record-req-srp-028
		 */
		async submit() {
			this.errors = fieldErrors(this.open, this.values, {}, this.t)
			if (Object.keys(this.errors).length > 0) {
				this.$nextTick(() => this.focusSummary())
				return
			}
			this.busy = true
			const result = await runAttachedAction(
				this.api,
				this.collection,
				this.row,
				this.open,
				this.values,
			)
			this.busy = false
			// The server refuses an empty required field per field
			// (REQ-SMF-024); those land in the summary like the form's own.
			const refused = serverFieldErrors(this.open, result.body?.errors, this.t)
			if (!result.ok && Object.keys(refused).length > 0) {
				this.errors = refused
				this.$nextTick(() => this.focusSummary())
				return
			}
			const success = this.open.successMessage
			this.message =
				result.ok && typeof success === 'string' && success !== ''
					? success
					: this.translate(result.messageKey)
			if (result.ok) {
				this.open = null
				this.values = {}
			}
		},
	},
}
</script>

<style scoped>
.pq-attached {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	margin-block: var(--utrecht-space-block-md, 1rem);
}

.pq-save__form {
	flex-basis: 100%;
	max-inline-size: 40rem;
}

.pq-save__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-save__status {
	flex-basis: 100%;
}

.pq-save__status:empty {
	display: none;
}
</style>
