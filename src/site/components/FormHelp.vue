<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"Hulp nodig?" on a form (help-texts-and-form-help): a link that opens a
	dialog with the portal's help details, merged with the form's own. It shows
	only when there are details, and it never touches the form's state, so the
	answers stay. Focus returns to the link when the dialog closes.
-->
<template>
	<div v-if="show" class="pq-form-help" data-testid="form-help">
		<button
			ref="trigger"
			type="button"
			class="utrecht-button utrecht-button--subtle pq-form-help__trigger"
			aria-haspopup="dialog"
			data-testid="form-help-open"
			@click="open = true">
			{{ say('trigger') }}
		</button>
		<FormHelpModal
			v-if="open"
			:help="details"
			:title="title"
			@close="closeDialog" />
	</div>
</template>

<script>
import FormHelpModal from '../modals/FormHelpModal.vue'
import { hasHelp, mergeHelp } from '../lib/help.js'
import strings from '../lib/helpStrings.js'
import { pageLocale } from '../pages/inbox/translate.js'

/**
 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
 */
export default {
	name: 'FormHelp',

	components: { FormHelpModal },

	props: {
		/** The portal's help details. */
		portalHelp: { type: Object, default: null },
		/** The form's own help details; each key overrides the portal's. */
		formHelp: { type: Object, default: null },
		/** The form's title, named in the e-mail subject and the phone note. */
		title: { type: String, default: '' },
	},

	data() {
		return { open: false }
	},

	computed: {
		/**
		 * @return {object} The merged details.
		 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
		 */
		details() {
			return mergeHelp(this.portalHelp, this.formHelp)
		},

		/**
		 * @return {boolean} Whether there is anything to show.
		 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
		 */
		show() {
			return hasHelp(this.details)
		},
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

		/**
		 * Close the dialog and give focus back to the link.
		 *
		 * @return {void}
		 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
		 */
		closeDialog() {
			this.open = false
			this.$nextTick(() => this.$refs.trigger?.focus?.())
		},
	},
}
</script>
