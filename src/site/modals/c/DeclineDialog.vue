<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section
		class="pq-rowaction pq-decline"
		:aria-label="label"
		data-testid="decline-dialog">
		<h3 ref="heading" class="utrecht-heading-3" tabindex="-1">
			{{ label }}{{ documentName ? `: ${documentName}` : '' }}
		</h3>
		<form novalidate @submit.prevent="submit">
			<div v-if="!done" class="utrecht-form-field">
				<div class="utrecht-form-field__label">
					<label class="utrecht-form-label" :for="fieldId">{{
						translate('Why do you decline?')
					}}</label>
				</div>
				<div class="utrecht-form-field__input">
					<textarea
						:id="fieldId"
						v-model="reason"
						class="utrecht-textarea utrecht-textarea--html-textarea"
						data-testid="decline-reason" />
				</div>
			</div>
			<div class="pq-rowaction__buttons">
				<button
					v-if="!done"
					type="submit"
					class="utrecht-button utrecht-button--primary-action"
					data-testid="decline-submit"
					:disabled="busy">
					{{ busy ? translate('Please wait…') : label }}
				</button>
				<button
					type="button"
					class="utrecht-button utrecht-button--subtle"
					:disabled="busy"
					@click="$emit('close')">
					{{ done ? translate('Close') : translate('Cancel') }}
				</button>
			</div>
		</form>
		<p
			class="utrecht-paragraph pq-rowaction__status"
			role="status"
			data-testid="decline-status">
			{{ message ? translate(message.key, message.vars) : '' }}
		</p>
	</section>
</template>

<script>
import { outcome } from '../../../shared/signing.js'
import { rowIdOf, translatorOr } from '../../components/c/forms.js'

import '@utrecht/form-field-css/dist/index.css'
import '@utrecht/textarea-css/dist/index.css'

/**
 * Decline to sign a document from its row (the Vue port of
 * DeclineDialog.jsx). The dialog asks why and forwards the reason; a refusal
 * keeps the dialog open with the answer in words.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-document-must-be-signable-and-declinable-from-its-row-req-srp-029
 */
export default {
	name: 'DeclineDialog',

	props: {
		/** The resolved `decline` row action. */
		action: { type: Object, required: true },
		/** The collection the row belongs to. */
		collection: { type: Object, required: true },
		/** The row. */
		row: { type: Object, required: true },
		/** The portal api (`forwardRowAction`). */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, default: null },
	},

	emits: ['done', 'close'],

	data() {
		return {
			reason: '',
			busy: false,
			message: null,
		}
	},

	computed: {
		translate() {
			return translatorOr(this.t)
		},

		rowId() {
			return rowIdOf(this.row)
		},

		documentName() {
			return (this.row && this.row.documentName) || ''
		},

		label() {
			return this.translate(this.action.label || 'Decline to sign')
		},

		done() {
			return this.message !== null && this.message.done === true
		},

		fieldId() {
			return `decline-reason-${this.rowId}`
		},
	},

	mounted() {
		if (this.$refs.heading) {
			this.$refs.heading.focus()
		}
	},

	methods: {
		/**
		 * Send the decline with its reason and show the answer.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-document-must-be-signable-and-declinable-from-its-row-req-srp-029
		 */
		async submit() {
			if (this.reason.trim() === '') {
				this.message = { done: false, key: 'Give a reason.' }
				return
			}
			this.busy = true
			const result = await this.api.forwardRowAction(
				this.collection,
				this.rowId,
				this.action.id,
				{ reason: this.reason.trim() },
			)
			const answer = outcome('decline', result, this.documentName)
			this.busy = false
			this.message = answer
			if (answer.done) {
				this.$emit('done')
			}
		},
	},
}
</script>

<style scoped>
.pq-rowaction {
	margin-block: var(--utrecht-space-block-md, 1rem);
	padding: var(--utrecht-space-block-md, 1rem);
	border: var(--utrecht-border-width-sm, 1px) solid
		var(--utrecht-color-grey-60, currentcolor);
}

.pq-rowaction__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-rowaction__status:empty {
	display: none;
}

.pq-decline .utrecht-textarea {
	inline-size: 100%;
}
</style>
