<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The help dialog of a form (the FormulierHulp board): intro, image, phone
	with a note on what to say, opening hours, the desk, "Vraag per e-mail" and
	Sluiten, each part only when the details hold it. It reads the details and
	nothing else, so it cannot change the form behind it. Escape closes it.
-->
<template>
	<div
		class="pq-form-help-modal"
		role="dialog"
		aria-modal="true"
		:aria-labelledby="headingId"
		data-testid="form-help-dialog"
		@keydown.esc="$emit('close')">
		<h2 :id="headingId" ref="heading" class="utrecht-heading-2" tabindex="-1">
			{{ say('title') }}
		</h2>
		<img
			v-if="help.image"
			class="pq-form-help-modal__image"
			:src="help.image"
			alt="" />
		<p v-if="help.intro" class="utrecht-paragraph">{{ help.intro }}</p>
		<p class="utrecht-paragraph">{{ say('keep') }}</p>
		<dl class="pq-form-help-modal__details">
			<div v-if="help.phone" data-testid="form-help-phone">
				<dt>{{ say('phone') }}</dt>
				<dd>
					{{ help.phone }}
					<span v-if="note" class="pq-form-help-modal__note">{{
						note
					}}</span>
				</dd>
			</div>
			<div v-if="help.hours" data-testid="form-help-hours">
				<dt>{{ say('hours') }}</dt>
				<dd>{{ help.hours }}</dd>
			</div>
			<div v-if="help.desk" data-testid="form-help-desk">
				<dt>{{ say('desk') }}</dt>
				<dd>{{ help.desk }}</dd>
			</div>
		</dl>
		<p v-if="mailto" class="utrecht-paragraph">
			<a class="utrecht-link" :href="mailto" data-testid="form-help-email">{{
				say('email')
			}}</a>
		</p>
		<button
			type="button"
			class="utrecht-button utrecht-button--primary-action"
			data-testid="form-help-close"
			@click="$emit('close')">
			{{ say('close') }}
		</button>
	</div>
</template>

<script>
import { mailtoFor, phoneNoteFor } from '../lib/help.js'
import strings from '../lib/helpStrings.js'
import { pageLocale } from '../pages/inbox/translate.js'

/**
 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
 */
export default {
	name: 'FormHelpModal',

	props: {
		/** The merged help details. */
		help: { type: Object, required: true },
		/** The form's title. */
		title: { type: String, default: '' },
	},

	emits: ['close'],

	computed: {
		/**
		 * @return {string} An id for the heading that names the dialog.
		 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
		 */
		headingId() {
			return 'pq-form-help-title'
		},

		/**
		 * @return {string} The note under the phone number, the form named.
		 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
		 */
		note() {
			return phoneNoteFor(this.help.phoneNote, this.title)
		},

		/**
		 * @return {string} The "Vraag per e-mail" link, or ''.
		 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
		 */
		mailto() {
			return mailtoFor(this.help.email, this.title)
		},
	},

	mounted() {
		this.$refs.heading?.focus?.()
	},

	methods: {
		/**
		 * @param {string} key A string key.
		 * @return {string} The words in the page language.
		 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
		 */
		say(key) {
			return (strings[pageLocale()] || strings.nl)[key]
		},
	},
}
</script>

<style scoped>
.pq-form-help-modal {
	margin-block: 1rem;
	padding: 1.5rem;
	border: 1px solid var(--nldesign-color-border-dark, currentcolor);
	border-radius: var(--utrecht-border-radius-md, 0.75rem);
	background: var(--nldesign-color-surface, Canvas);
}

.pq-form-help-modal__image {
	max-inline-size: 100%;
}

.pq-form-help-modal__details > div {
	margin-block: 0.5rem;
}

.pq-form-help-modal__details dt {
	font-weight: 700;
}

.pq-form-help-modal__details dd {
	margin: 0;
}

.pq-form-help-modal__note {
	display: block;
}
</style>
