<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<form
		class="pq-propose-form"
		:aria-label="translate('Propose a change')"
		data-testid="propose-form"
		novalidate
		@submit.prevent="submit">
		<SchemaField
			v-for="field in fields"
			:id="`propose-${action.id}-${field}`"
			:key="field"
			v-model="values[field]"
			:field="field"
			:label="field"
			:t="t" />
		<SchemaField
			:id="`propose-${action.id}-note`"
			v-model="note"
			field="note"
			:label="translate('Note')"
			:config="{ size: 'large' }"
			input="textarea"
			:t="t" />

		<p
			v-if="error !== ''"
			class="utrecht-paragraph pq-propose-form__error"
			role="alert"
			data-testid="propose-error">
			{{ error }}
		</p>

		<div class="pq-propose-form__buttons">
			<button
				type="submit"
				class="utrecht-button utrecht-button--primary-action"
				:disabled="submitting"
				data-testid="propose-submit">
				{{ submitting ? translate('Please wait…') : translate('Send proposal') }}
			</button>
			<button
				type="button"
				class="utrecht-button utrecht-button--subtle"
				@click="$emit('cancel')">
				{{ translate('Cancel') }}
			</button>
		</div>
	</form>
</template>

<script>
import SchemaField from './SchemaField.vue'
import { proposalStart, proposedChanges, translatorOr } from './forms.js'

/**
 * Propose a change to a record (the Vue port of ProposeChangeForm.jsx). It
 * renders the action's `proposable` fields, filled from the row, plus a note,
 * and sends only the fields that changed. The server checks the same list
 * again and refuses a proposal with nothing changed.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-propose-a-change-req-srp-024
 */
export default {
	name: 'ProposeChangeForm',

	components: { SchemaField },

	props: {
		/** The `propose-change` action (`id`, `proposable`, `register`, `schema`). */
		action: { type: Object, required: true },
		/** The record the proposal is about. */
		row: { type: Object, default: null },
		/** Sends the proposal: `(changes, note) => Promise<{ok}>`. */
		send: { type: Function, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, default: null },
	},

	emits: ['cancel', 'sent'],

	data() {
		return {
			values: proposalStart(this.action, this.row),
			note: '',
			error: '',
			submitting: false,
		}
	},

	computed: {
		translate() {
			return translatorOr(this.t)
		},

		fields() {
			return this.action.proposable || []
		},
	},

	methods: {
		/**
		 * Send only what changed, with the note.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-propose-a-change-req-srp-024
		 */
		async submit() {
			this.error = ''
			const changes = proposedChanges(this.action, this.row, this.values)
			if (changes.length === 0) {
				this.error = this.translate('Change at least one field before you send a proposal.')
				return
			}
			this.submitting = true
			const result = await this.send(changes, this.note)
			this.submitting = false
			if (!result || result.ok === false) {
				this.error = this.translate('Sending the proposal did not work.')
				return
			}
			this.note = ''
			this.$emit('sent', result)
		},
	},
}
</script>

<style scoped>
.pq-propose-form__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-propose-form__error {
	color: var(--utrecht-form-field-error-message-color, inherit);
}
</style>
