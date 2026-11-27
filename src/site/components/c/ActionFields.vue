<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<SchemaField
		v-for="field in shown"
		:id="`${base}-${field}`"
		:key="field"
		:modelValue="values[field]"
		:field="field"
		:label="labelOf(field)"
		:config="configOf(field)"
		:input="inputOf(field)"
		:options="options[field] || []"
		:files="files[field] || []"
		:fileKey="fileKey"
		:error="errors[field] || ''"
		:t="t"
		@update:modelValue="(value) => $emit('update', field, value)"
		@pick="(picked) => $emit('pick', field, picked)" />
</template>

<script>
import SchemaField from './SchemaField.vue'
import { fieldConfig, fieldInput, fieldLabel, formFields } from './forms.js'

/**
 * The fields an action declares, each as its own input shaped by the action's
 * `fieldConfigs` and options (the field renderer of the schema form, shared
 * with the row action dialog). It keeps no answers of its own: the form that
 * holds it owns the values, and every typed value comes back as `update`.
 *
 * @spec openspec/changes/archive/2026-11-24-case-actions-row-inputs-and-conditions/specs/portal-row-action-inputs/spec.md#requirement-a-row-action-collects-the-fields-it-declares-req-rai-001
 */
export default {
	name: 'ActionFields',

	components: { SchemaField },

	// The fields are the root (no wrapper), so the form's own layout holds.
	inheritAttrs: false,

	props: {
		/** The action whose `fields`, `fieldConfigs` and options shape the inputs. */
		action: { type: Object, required: true },
		/** The fields to show, in order; the action's `fields` when null. */
		fields: { type: Array, default: null },
		/** The typed values by field. */
		values: { type: Object, default: () => ({}) },
		/** The resolved options by field. */
		options: { type: Object, default: () => ({}) },
		/** The picked files by file field. */
		files: { type: Object, default: () => ({}) },
		/** Bumped by the form to clear a file input. */
		fileKey: { type: Number, default: 0 },
		/** The inline error by field. */
		errors: { type: Object, default: () => ({}) },
		/** The prefix of every input id; `f-<action id>` by default. */
		idBase: { type: String, default: '' },
		/** The translator `t(key, vars)`. */
		t: { type: Function, default: null },
	},

	emits: ['update', 'pick'],

	computed: {
		/**
		 * @return {string} The prefix of every input id.
		 * @spec openspec/changes/archive/2026-11-24-case-actions-row-inputs-and-conditions/specs/portal-row-action-inputs/spec.md#requirement-a-row-action-collects-the-fields-it-declares-req-rai-001
		 */
		base() {
			return this.idBase !== '' ? this.idBase : `f-${this.action.id}`
		},

		/**
		 * @return {string[]} The fields drawn: the ones given, else every field the action declares.
		 * @spec openspec/changes/archive/2026-11-24-case-actions-row-inputs-and-conditions/specs/portal-row-action-inputs/spec.md#requirement-a-row-action-collects-the-fields-it-declares-req-rai-001
		 */
		shown() {
			return Array.isArray(this.fields) ? this.fields : formFields(this.action)
		},
	},

	methods: {
		/**
		 * @param {string} field The field.
		 * @return {string} Its label.
		 * @spec openspec/changes/archive/2026-11-24-case-actions-row-inputs-and-conditions/specs/portal-row-action-inputs/spec.md#requirement-a-row-action-collects-the-fields-it-declares-req-rai-001
		 */
		labelOf(field) {
			return fieldLabel(this.action, field)
		},

		/**
		 * @param {string} field The field.
		 * @return {object} Its declared field config.
		 * @spec openspec/changes/archive/2026-11-24-case-actions-row-inputs-and-conditions/specs/portal-row-action-inputs/spec.md#requirement-a-row-action-collects-the-fields-it-declares-req-rai-001
		 */
		configOf(field) {
			return fieldConfig(this.action, field)
		},

		/**
		 * @param {string} field The field.
		 * @return {string} The input it is drawn as.
		 * @spec openspec/changes/archive/2026-11-24-case-actions-row-inputs-and-conditions/specs/portal-row-action-inputs/spec.md#requirement-a-row-action-collects-the-fields-it-declares-req-rai-001
		 */
		inputOf(field) {
			return fieldInput(this.action, field, this.options[field])
		},
	},
}
</script>
