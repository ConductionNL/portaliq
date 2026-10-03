<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<fieldset
		v-if="group"
		class="utrecht-form-fieldset utrecht-form-field pq-field-shell pq-field-shell--group"
		:class="{ 'utrecht-form-field--invalid': error !== '' }"
		:aria-describedby="describedBy">
		<legend
			:id="`${id}-label`"
			class="utrecht-form-label pq-field-shell__legend">
			{{ label }}<LabelSuffix v-if="!required" :text="optionalLabel" />
		</legend>
		<div
			v-if="help !== ''"
			:id="`${id}-help`"
			class="utrecht-form-field-description">
			{{ help }}
		</div>
		<div
			v-if="error !== ''"
			:id="`${id}-error`"
			class="utrecht-form-field-error-message"
			:data-testid="errorTestid || undefined">
			{{ error }}
		</div>
		<div class="utrecht-form-field__input">
			<slot :describedBy="describedBy" :invalid="error !== ''" />
		</div>
	</fieldset>
	<div
		v-else
		class="utrecht-form-field pq-field-shell"
		:class="{ 'utrecht-form-field--invalid': error !== '' }">
		<div class="utrecht-form-field__label">
			<label :id="`${id}-label`" :for="id" class="utrecht-form-label">
				{{ label }}<LabelSuffix v-if="!required" :text="optionalLabel" />
			</label>
		</div>
		<div
			v-if="help !== ''"
			:id="`${id}-help`"
			class="utrecht-form-field-description">
			{{ help }}
		</div>
		<div
			v-if="error !== ''"
			:id="`${id}-error`"
			class="utrecht-form-field-error-message"
			:data-testid="errorTestid || undefined">
			{{ error }}
		</div>
		<div class="utrecht-form-field__input">
			<slot :describedBy="describedBy" :invalid="error !== ''" />
		</div>
	</div>
</template>

<script>
import LabelSuffix from './LabelSuffix.vue'

import '@utrecht/form-field-css/dist/index.css'
import '@utrecht/form-field-description-css/dist/index.css'
import '@utrecht/form-field-error-message-css/dist/index.css'

/**
 * One field of a site form, in the NL Design System order: the label (or the
 * legend of a group), the description, the error message, then the input in
 * the default slot. A field the resident may leave empty carries
 * "(niet verplicht)" inside its label; a required one carries nothing. The
 * slot receives `describedBy`, the ids of the description and the error, for
 * the input's `aria-describedby`, and `invalid`.
 *
 * With `group` the field is a fieldset whose legend is the question, for an
 * answer of several inputs such as a date's day, month and year. The fieldset
 * then carries the `aria-describedby` itself.
 *
 * The label (or legend) has the id `${id}-label`. An input in the slot names
 * it in `aria-labelledby` as well as through `for`, so a static label check
 * that reads one file at a time sees the association too.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-site-form-must-mark-the-fields-that-are-not-required-req-smf-001
 */
export default {
	name: 'FieldShell',

	components: { LabelSuffix },

	props: {
		/** The input's id; the label points at it, and the help and error ids derive from it. */
		id: { type: String, required: true },
		/** The question. */
		label: { type: String, required: true },
		/** Whether the field must be filled in. */
		required: { type: Boolean, default: false },
		/** The suffix of an optional field, in the site's language. */
		optionalLabel: { type: String, default: '(niet verplicht)' },
		/** The description under the label, '' for none. */
		help: { type: String, default: '' },
		/** The error message, '' when none. */
		error: { type: String, default: '' },
		/** Render a fieldset with a legend instead of a label. */
		group: { type: Boolean, default: false },
		/** The error message's `data-testid`. */
		errorTestid: { type: String, default: '' },
	},

	computed: {
		/**
		 * The ids that describe the input: its description, then its error.
		 *
		 * @return {string|undefined} The ids, or undefined when there are none.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
		 */
		describedBy() {
			const ids = []
			if (this.help !== '') {
				ids.push(`${this.id}-help`)
			}
			if (this.error !== '') {
				ids.push(`${this.id}-error`)
			}
			return ids.length > 0 ? ids.join(' ') : undefined
		},
	},
}
</script>

<style scoped>
.pq-field-shell--group {
	border: 0;
	margin-inline: 0;
	min-inline-size: 0;
	padding: 0;
}

.pq-field-shell__legend {
	padding: 0;
}
</style>
