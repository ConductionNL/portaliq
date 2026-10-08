<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"Verklaringen" on the review step (form-statements-intro-and-confirmation-
	mail REQ-FCI-002), drawn on the board WooVerzoekControleren: the statement
	of truth and the privacy consent the form asks, unchecked. A required
	statement without a text is shown as blocked, never as ticked.
-->
<template>
	<fieldset class="pq-statements" data-testid="form-statements">
		<legend class="utrecht-heading-3">
			{{ words.heading }}
		</legend>
		<div v-for="statement in statements" :key="statement.key" class="pq-statements__item">
			<label>
				<input
					type="checkbox"
					:checked="modelValue.includes(statement.key)"
					:disabled="!statement.text"
					:aria-invalid="errors[statement.key] ? 'true' : 'false'"
					:data-testid="`statement-${statement.key}`"
					@change="toggle(statement.key)" />
				<span>{{ statement.text || words.noText }}</span>
			</label>
			<p
				v-if="errors[statement.key]"
				class="utrecht-paragraph pq-statements__error"
				role="alert"
				:data-testid="`statement-error-${statement.key}`">
				{{ errors[statement.key] }}
			</p>
		</div>
	</fieldset>
</template>

<script>
import { pageLocale } from '../../pages/inbox/translate.js'
import { toggleRef } from './family.js'

import '@utrecht/paragraph-css/dist/index.css'

const WORDS = {
	nl: { heading: 'Verklaringen', noText: 'Deze verklaring heeft nog geen tekst.' },
	en: { heading: 'Statements', noText: 'This statement has no text yet.' },
}

/**
 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t03
 */
export default {
	name: 'StatementsBlock',

	props: {
		/** The statements the form asks: `[{key, required, text, version}]`. */
		statements: { type: Array, default: () => [] },
		/** The keys ticked. */
		modelValue: { type: Array, default: () => [] },
		/** The error per statement key. */
		errors: { type: Object, default: () => ({}) },
		/** The reader's locale; the page language when empty. */
		locale: { type: String, default: '' },
	},

	emits: ['update:modelValue'],

	computed: {
		words() {
			return WORDS[pageLocale(this.locale)] || WORDS.nl
		},
	},

	methods: {
		/**
		 * @param {string} key The statement ticked or unticked.
		 * @return {void}
		 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t03
		 */
		toggle(key) {
			this.$emit('update:modelValue', toggleRef(this.modelValue, key))
		},
	},
}
</script>
