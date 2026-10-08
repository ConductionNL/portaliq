<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The language picker on the messages and the news screen. It writes the
	account's own `messageLanguage`, so both screens follow one choice.
-->
<template>
	<div class="utrecht-form-field pq-language-picker">
		<label class="utrecht-form-label" :for="id">{{ label }}</label>
		<select
			:id="id"
			class="utrecht-select"
			:value="language"
			:aria-describedby="`${id}-hint`"
			@change="$emit('change', $event.target.value)">
			<option value="">{{ t('As written') }}</option>
			<option v-for="tag in languages" :key="tag" :value="tag">
				{{ labelFor(tag) }}
			</option>
		</select>
		<p :id="`${id}-hint`" class="utrecht-paragraph pq-language-picker__hint">
			{{ hint }}
		</p>
		<p v-if="error" class="utrecht-paragraph pq-error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { MESSAGE_LANGUAGES, pickerLabel } from '../../pages/inbox/translation.js'

/**
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-messages-in-their-chosen-language-req-srp-032
 */
export default {
	name: 'MessageLanguagePicker',

	props: {
		/** The select's id, unique on the page. */
		id: { type: String, required: true },
		/** The label text. */
		label: { type: String, required: true },
		/** The sentence under the picker. */
		hint: { type: String, default: '' },
		/** The current language, '' for as written. */
		language: { type: String, default: '' },
		/** An error to announce. */
		error: { type: String, default: '' },
		/** The translator. */
		t: { type: Function, required: true },
		/** The page language. */
		locale: { type: String, default: 'nl' },
	},

	emits: ['change'],

	computed: {
		/**
		 * @return {Array<string>} The languages a guardian can pick.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-messages-in-their-chosen-language-req-srp-032
		 */
		languages() {
			return MESSAGE_LANGUAGES
		},
	},

	methods: {
		/**
		 * @param {string} tag The language tag.
		 * @return {string} Its label in the picker.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-guardian-must-read-messages-in-their-chosen-language-req-srp-032
		 */
		labelFor(tag) {
			return pickerLabel(tag, this.locale)
		},
	},
}
</script>
