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
	<dialog
		ref="dialog"
		class="pq-form-help-modal"
		:aria-labelledby="headingId"
		data-testid="form-help-dialog"
		@cancel.prevent="$emit('close')">
		<div class="pq-form-help-modal__top">
			<div>
				<p v-if="title" class="pq-form-help-modal__overline">
					{{ title }}
				</p>
				<h2 :id="headingId" ref="heading" class="utrecht-heading-3" tabindex="-1">
					{{ say('title') }}
				</h2>
			</div>
			<button
				type="button"
				class="pq-form-help-modal__x"
				:aria-label="say('close')"
				data-testid="form-help-x"
				@click="$emit('close')">
				<span aria-hidden="true">×</span>
			</button>
		</div>
		<div class="pq-form-help-modal__intro" :class="{ 'pq-form-help-modal__intro--image': help.image }">
			<img
				v-if="help.image"
				class="pq-form-help-modal__image"
				:src="help.image"
				alt="" />
			<div>
				<p v-if="help.intro" class="utrecht-paragraph">{{ help.intro }}</p>
				<p class="utrecht-paragraph">{{ say('keep') }}</p>
			</div>
		</div>
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
		<div class="pq-form-help-modal__buttons">
			<button
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				data-testid="form-help-close"
				@click="$emit('close')">
				{{ say('close') }}
			</button>
			<a
				v-if="mailto"
				class="utrecht-button utrecht-button--primary-action pq-form-help-modal__mail"
				:href="mailto"
				data-testid="form-help-email">{{ say('email') }}</a>
		</div>
	</dialog>
</template>

<script>
import { mailtoFor, phoneNoteFor } from '../lib/help.js'
import strings from '../lib/helpStrings.js'
import { pageLocale } from '../pages/inbox/translate.js'
import { closeDialog, openDialog } from './forms/draftDialog.js'

/**
 * @spec openspec/changes/archive/2026-11-25-help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
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
		 * @spec openspec/changes/archive/2026-11-25-help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
		 */
		headingId() {
			return 'pq-form-help-title'
		},

		/**
		 * @return {string} The note under the phone number, the form named.
		 * @spec openspec/changes/archive/2026-11-25-help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
		 */
		note() {
			return phoneNoteFor(this.help.phoneNote, this.title)
		},

		/**
		 * @return {string} The "Vraag per e-mail" link, or ''.
		 * @spec openspec/changes/archive/2026-11-25-help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
		 */
		mailto() {
			return mailtoFor(this.help.email, this.title)
		},
	},

	/**
	 * Open as a real modal dialog (a backdrop, the page behind it inert) and put focus on the heading.
	 *
	 * @return {void}
	 * @spec openspec/changes/archive/2026-11-25-help-texts-and-form-help/tasks.md#t07
	 */
	mounted() {
		openDialog(this.$refs.dialog, this.$refs.heading)
	},

	/**
	 * Close the dialog before it leaves the page.
	 *
	 * @return {void}
	 * @spec openspec/changes/archive/2026-11-25-help-texts-and-form-help/tasks.md#t07
	 */
	beforeUnmount() {
		closeDialog(this.$refs.dialog)
	},

	methods: {
		/**
		 * @param {string} key A string key.
		 * @return {string} The words in the page language.
		 * @spec openspec/changes/archive/2026-11-25-help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
		 */
		say(key) {
			return (strings[pageLocale()] || strings.nl)[key]
		},
	},
}
</script>

<style scoped>
/*
 * The dialog as board FormulierHulp draws it (help-texts-and-form-help T07):
 * a centred modal with the form's name above the title and a close button,
 * the image beside the intro, the details as rows, and the buttons in a
 * footer with "Vraag per e-mail" as the main one.
 */
.pq-form-help-modal {
	inset: 0;
	margin: auto;
	inline-size: min(28rem, calc(100vw - 2rem));
	max-block-size: calc(100vh - 2rem);
	padding: 1.5rem;
	border: 0;
	border-radius: var(--utrecht-border-radius-md, 0.5rem);
	color: var(--utrecht-document-color, inherit);
	background: var(--utrecht-document-background-color, Canvas);
	box-shadow: 0 0.5rem 2rem color-mix(in srgb, currentcolor 25%, transparent);
}

.pq-form-help-modal::backdrop {
	background: color-mix(in srgb, CanvasText 40%, transparent);
}

.pq-form-help-modal > * + * {
	margin-block-start: 1rem;
}

.pq-form-help-modal__top {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 1rem;
}

.pq-form-help-modal__top h2 {
	margin: 0;
}

.pq-form-help-modal__overline {
	margin: 0;
	font-size: 0.75em;
}

.pq-form-help-modal__x {
	min-inline-size: 2.75rem;
	min-block-size: 2.75rem;
	border: 0;
	background: none;
	color: inherit;
	font-size: 1.5em;
	line-height: 1;
	cursor: pointer;
}

.pq-form-help-modal__intro--image {
	display: grid;
	grid-template-columns: 8rem minmax(0, 1fr);
	gap: 1rem;
	align-items: start;
}

.pq-form-help-modal__intro p {
	margin: 0 0 0.5rem;
}

.pq-form-help-modal__image {
	max-inline-size: 100%;
	border-radius: var(--utrecht-border-radius-sm, 0.25rem);
}

.pq-form-help-modal__details {
	margin-block-end: 0;
	border-block-start: 1px solid var(--nldesign-color-border-light, var(--utrecht-color-grey-80, color-mix(in srgb, currentcolor 18%, transparent)));
}

.pq-form-help-modal__details > div {
	display: grid;
	grid-template-columns: minmax(0, 2fr) minmax(0, 3fr);
	gap: 1rem;
	padding-block: 0.75rem;
	border-block-end: 1px solid var(--nldesign-color-border-light, var(--utrecht-color-grey-80, color-mix(in srgb, currentcolor 18%, transparent)));
	font-size: 0.875em;
}

.pq-form-help-modal__details dt {
	font-weight: 700;
}

.pq-form-help-modal__details dd {
	margin: 0;
}

.pq-form-help-modal__note {
	display: block;
	color: var(--nldesign-color-text-muted, var(--utrecht-color-grey-30, GrayText));
}

.pq-form-help-modal__buttons {
	display: flex;
	flex-wrap: wrap;
	justify-content: flex-end;
	gap: 0.5rem;
}

.pq-form-help-modal__mail {
	text-decoration: none;
}
</style>
