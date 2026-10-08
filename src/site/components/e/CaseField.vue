<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	One answer on the citizen's case (what-the-citizen-may-write-on-their-own-
	case), ported from CaseField in the React portal's CitizenCase.jsx. An
	open field is an input; a closed field is text with the sentence that says
	why. Never a disabled control without an explanation.
-->
<template>
	<div
		class="pq-case-field"
		:class="writable ? 'open' : 'closed'"
		:data-testid="`case-field-${field}`">
		<template v-if="writable">
			<label :for="inputId" class="utrecht-form-label">{{ field }}</label>
			<input
				:id="inputId"
				class="utrecht-textbox"
				type="text"
				:value="text"
				:data-testid="`case-input-${field}`"
				@input="$emit('change', field, $event.target.value)" />
		</template>
		<template v-else>
			<p :id="inputId" class="utrecht-form-label pq-case-field__label">
				{{ field }}
			</p>
			<p
				class="utrecht-paragraph pq-case-value"
				:data-testid="`case-value-${field}`">
				{{ untranslated ? '' : text
				}}<NoTranslate v-if="untranslated" :value="text" />
			</p>
			<p
				v-if="!quiet"
				class="utrecht-paragraph pq-case-reason"
				:data-testid="`case-reason-${field}`">
				{{
					state?.reason
					|| t('This answer cannot be changed from the portal.')
				}}
			</p>
		</template>
	</div>
</template>

<script>
import NoTranslate from '../NoTranslate.vue'

// Formats whose values are personal data (REQ-PDU-001).
const PERSONAL_FORMATS = ['email', 'uri', 'url', 'telephone', 'tel', 'phone']

export default {
	name: 'CaseField',

	components: { NoTranslate },

	props: {
		/** The field name. */
		field: { type: String, required: true },
		/** The field's `{ writable, reason }` state from the writable set. */
		state: { type: Object, default: null },
		/** The current answer. */
		value: { type: [Boolean, String, Number, Object, Array], default: null },
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
		/** The field's schema format (`email`, `telephone`, `uri`), when known. */
		format: { type: String, default: '' },
		/** The contribution marked the field `personal: true`. */
		personal: { type: Boolean, default: false },
		/** Say nothing about why it cannot change: the case is over. */
		quiet: { type: Boolean, default: false },
	},

	emits: ['change'],

	computed: {
		writable() {
			return this.state?.writable === true
		},

		/**
		 * Whether the value is personal data the browser must not translate.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/personal-data-left-untranslated/specs/portaliq-cms/spec.md#requirement-browser-translation-leaves-names-and-personal-data-alone-req-pdu-001
		 */
		untranslated() {
			return (
				this.personal === true
				|| PERSONAL_FORMATS.includes(String(this.format).toLowerCase())
			)
		},

		text() {
			return this.value === null || this.value === undefined
				? ''
				: String(this.value)
		},

		inputId() {
			return `pq-case-field-${this.field}`
		},
	},
}
</script>

<style scoped>
.pq-case-field {
	margin-block-end: var(--utrecht-space-block-md, 1rem);
}

.pq-case-field__label,
.pq-case-value {
	margin: 0;
}

.pq-case-reason {
	margin: 0;
	font-style: italic;
}
</style>
