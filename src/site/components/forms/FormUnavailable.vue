<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A form that takes no submissions now, drawn on the Zuiddrecht board
	FormulierNietBeschikbaar: maintenance, replaced, all places taken, or
	not open yet. The resident's saved answers are not touched.
-->
<template>
	<div
		class="pq-form-unavailable"
		role="status"
		data-testid="form-unavailable"
		:data-situation="situationKind">
		<h2 class="utrecht-heading-2">
			{{ view.heading }}
		</h2>
		<p
			v-for="(line, index) in view.lines"
			:key="index"
			class="utrecht-paragraph"
			data-testid="form-unavailable-line">
			{{ line }}
		</p>
		<a
			v-if="view.link"
			class="utrecht-button-link utrecht-button-link--html-a utrecht-button-link--primary-action"
			:href="view.link.href"
			data-testid="form-unavailable-link">
			{{ view.link.label }}
		</a>
		<section v-if="view.contact && contactLines.length > 0" data-testid="form-unavailable-contact">
			<h3 class="utrecht-heading-3">
				{{ words.contactHeading }}
			</h3>
			<p v-for="line in contactLines" :key="line" class="utrecht-paragraph">
				{{ line }}
			</p>
		</section>
	</div>
</template>

<script>
import { pageLocale } from '../../pages/inbox/translate.js'
import { unavailableView, unavailableWords } from './unavailable.js'

import '@utrecht/button-link-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

/**
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-form-opens-and-closes-on-set-dates-and-after-a-set-number-of-submissions-req-fgv-001
 */
export default {
	name: 'FormUnavailable',

	props: {
		/** The server's `unavailable` answer: `{situation, ...}`. */
		situation: { type: Object, default: null },
		/** The form's name. */
		formName: { type: String, default: '' },
		/** The portal's and the form's help details (`phone`, `email`), for the contact line. */
		help: { type: Object, default: null },
		/** The reader's locale; the page language when empty. */
		locale: { type: String, default: '' },
	},

	computed: {
		/**
		 * @return {string} `nl` or `en`.
		 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-form-opens-and-closes-on-set-dates-and-after-a-set-number-of-submissions-req-fgv-001
		 */
		language() {
			return pageLocale(this.locale)
		},

		/**
		 * @return {object} The words in the page language.
		 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-form-opens-and-closes-on-set-dates-and-after-a-set-number-of-submissions-req-fgv-001
		 */
		words() {
			return unavailableWords(this.language)
		},

		/**
		 * @return {object} The heading, lines and link for the situation.
		 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-form-opens-and-closes-on-set-dates-and-after-a-set-number-of-submissions-req-fgv-001
		 */
		view() {
			return unavailableView(this.situation, this.formName, this.language)
		},

		/**
		 * @return {string} The situation's name, for tests and styling.
		 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-form-opens-and-closes-on-set-dates-and-after-a-set-number-of-submissions-req-fgv-001
		 */
		situationKind() {
			return String(this.situation?.situation || 'closed')
		},

		/**
		 * @return {string[]} The phone number and e-mail address the portal gives, when any.
		 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-form-opens-and-closes-on-set-dates-and-after-a-set-number-of-submissions-req-fgv-001
		 */
		contactLines() {
			const help = this.help || {}
			return ['phone', 'email'].map((key) => String(help[key] || '').trim()).filter((value) => value !== '')
		},
	},
}
</script>
