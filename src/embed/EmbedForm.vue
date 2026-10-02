<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	THE FORM MOUNT POINT OF THE EMBED FRAME (site-reaches-portal-parity
	REQ-SRP-047).

	For now this renders the same plain list of labelled text inputs the React
	frame (src/portal/components/EmbeddedForm.jsx) rendered, so a framed form
	works from the day the frame loads this entry. It is meant to be REPLACED
	by slice c's SchemaForm (src/site/components/c/SchemaForm.vue) once that
	lands, which knows field types, conditions and file fields. Keep this
	component's contract when swapping: props `fields`, `fieldErrors`, `busy`,
	`t`; emits `submit(answers)`.
-->
<template>
	<form class="pq-embed-form" data-testid="embed-form" @submit.prevent="submit">
		<div
			v-for="field in fields"
			:key="field.name"
			class="utrecht-form-field utrecht-form-field--text">
			<div class="utrecht-form-field__label">
				<label class="utrecht-form-label" :for="`embed-${field.name}`">{{
					labelFor(field)
				}}</label>
			</div>
			<div
				v-if="fieldErrors[field.name]"
				:id="`embed-${field.name}-error`"
				class="utrecht-form-field-error-message">
				{{ fieldErrors[field.name] }}
			</div>
			<div class="utrecht-form-field__input">
				<input
					:id="`embed-${field.name}`"
					v-model="answers[field.name]"
					class="utrecht-textbox utrecht-textbox--html-input"
					:class="{
						'utrecht-textbox--invalid': Boolean(fieldErrors[field.name]),
					}"
					type="text"
					:name="field.name"
					:required="field.required === true"
					:aria-invalid="fieldErrors[field.name] ? 'true' : null"
					:aria-describedby="
						fieldErrors[field.name] ? `embed-${field.name}-error` : null
					"
					:data-testid="`embed-field-${field.name}`" />
			</div>
		</div>

		<button
			type="submit"
			class="utrecht-button utrecht-button--primary-action"
			data-testid="embed-submit"
			:disabled="busy">
			{{ busy ? t('Sending…') : t('Send') }}
		</button>
	</form>
</template>

<script>
import { labelFor } from '../shared/embedCopy.js'
import { initialAnswers } from './frame.js'

export default {
	name: 'EmbedForm',

	props: {
		/** The form's fields from the frame payload: `{name, label, required, preset}`. */
		fields: {
			type: Array,
			default: () => [],
		},

		/** Field name to the server's message for it. */
		fieldErrors: {
			type: Object,
			default: () => ({}),
		},

		/** True while the answers are being sent. */
		busy: {
			type: Boolean,
			default: false,
		},

		/** The translator `t(key, vars)`. */
		t: {
			type: Function,
			required: true,
		},
	},

	emits: ['submit'],

	data() {
		return {
			answers: initialAnswers(this.fields),
		}
	},

	methods: {
		labelFor,

		/**
		 * Hand the answers to the frame.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-embed-frame-must-render-its-form-from-a-small-entry-req-srp-047
		 */
		submit() {
			if (this.busy) {
				return
			}

			this.$emit('submit', { ...this.answers })
		},
	},
}
</script>

<style scoped>
.pq-embed-form {
	display: flex;
	flex-direction: column;
	gap: var(--utrecht-space-block-md, 1rem);
	align-items: flex-start;
}

.pq-embed-form .utrecht-form-field {
	inline-size: 100%;
}
</style>
