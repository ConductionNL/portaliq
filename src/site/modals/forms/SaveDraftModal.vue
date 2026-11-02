<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	Before a form saves its answers (board FormulierOpslaan, REQ-FSR-001):
	what saving does, how long the answers are kept, and three ways on.
	Escape and "Doorgaan met invullen" both emit `continue`.
-->
<template>
	<dialog
		ref="dialog"
		class="pq-draft-dialog"
		aria-labelledby="pq-save-draft-title"
		aria-describedby="pq-save-draft-body"
		data-testid="form-save-draft-dialog"
		@cancel.prevent="$emit('continue')">
		<p v-if="formTitle" class="pq-draft-dialog__form">
			{{ formTitle }}
		</p>
		<h2
			id="pq-save-draft-title"
			ref="heading"
			class="utrecht-heading-3"
			tabindex="-1">
			{{ t('Do you want to continue later?') }}
		</h2>
		<p id="pq-save-draft-body" class="utrecht-paragraph">
			{{
				t(
					'Save your answers, so you do not have to start again. We send nothing yet and make no case yet.',
				)
			}}
		</p>
		<p
			class="utrecht-paragraph pq-draft-dialog__keep"
			data-testid="form-save-draft-keep">
			{{
				t(
					'We keep your answers for {days} days, until {date}. Open this form again to continue.',
					{ days, date: until },
				)
			}}
		</p>
		<div class="pq-draft-dialog__buttons">
			<button
				type="button"
				class="utrecht-button utrecht-button--subtle"
				data-testid="form-save-draft-leave"
				@click="$emit('leave')">
				{{ t('Stop without saving') }}
			</button>
			<button
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				data-testid="form-save-draft-continue"
				@click="$emit('continue')">
				{{ t('Continue filling in') }}
			</button>
			<button
				type="button"
				class="utrecht-button utrecht-button--primary-action"
				data-testid="form-save-draft-save"
				:disabled="busy"
				@click="$emit('save')">
				{{ t('Save and stop') }}
			</button>
		</div>
	</dialog>
</template>

<script>
import { closeDialog, openDialog } from './draftDialog.js'

export default {
	name: 'SaveDraftModal',

	props: {
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
		/** The form's name, above the question. */
		formTitle: { type: String, default: '' },
		/** How many days the answers are kept. */
		days: { type: Number, required: true },
		/** The day they are kept until, in words. */
		until: { type: String, required: true },
		/** Whether the answers are being saved. */
		busy: { type: Boolean, default: false },
	},

	emits: ['save', 'continue', 'leave'],

	mounted() {
		openDialog(this.$refs.dialog, this.$refs.heading)
	},

	beforeUnmount() {
		closeDialog(this.$refs.dialog)
	},
}
</script>
