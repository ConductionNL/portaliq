<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	One answer on the citizen's case (what-the-citizen-may-write-on-their-own-
	case), ported from CaseField in src/portal/components/CitizenCase.jsx. An
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
				{{ text }}
			</p>
			<p
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
export default {
	name: 'CaseField',

	props: {
		/** The field name. */
		field: { type: String, required: true },
		/** The field's `{ writable, reason }` state from the writable set. */
		state: { type: Object, default: null },
		/** The current answer. */
		value: { type: [Boolean, String, Number, Object, Array], default: null },
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
	},

	emits: ['change'],

	computed: {
		writable() {
			return this.state?.writable === true
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
