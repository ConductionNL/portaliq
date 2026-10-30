<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	Opening a form the resident already started (board FormulierHervatten,
	REQ-FSR-002): continue with the saved answers, or start again and throw
	them away. Escape continues, which loses nothing.
-->
<template>
	<dialog
		ref="dialog"
		class="pq-draft-dialog"
		aria-labelledby="pq-resume-draft-title"
		aria-describedby="pq-resume-draft-body"
		data-testid="form-resume-draft-dialog"
		@cancel.prevent="$emit('resume')">
		<p v-if="formTitle" class="pq-draft-dialog__form">
			{{ formTitle }}
		</p>
		<h2
			id="pq-resume-draft-title"
			ref="heading"
			class="utrecht-heading-3"
			tabindex="-1">
			{{ t('You already started this form') }}
		</h2>
		<p id="pq-resume-draft-body" class="utrecht-paragraph">
			{{
				savedAt
					? t(
							'You filled in answers on {moment}. Do you want to continue with them?',
							{
								moment: savedAt,
							},
						)
					: t(
							'You already filled in answers. Do you want to continue with them?',
						)
			}}
		</p>
		<p class="utrecht-paragraph">
			{{ t('If you start again, we throw the earlier answers away.') }}
		</p>
		<div class="pq-draft-dialog__buttons">
			<button
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				data-testid="form-resume-draft-restart"
				:disabled="busy"
				@click="$emit('restart')">
				{{ t('Start again') }}
			</button>
			<button
				type="button"
				class="utrecht-button utrecht-button--primary-action"
				data-testid="form-resume-draft-resume"
				@click="$emit('resume')">
				{{ t('Continue with my answers') }}
			</button>
		</div>
	</dialog>
</template>

<script>
import { closeDialog, openDialog } from './draftDialog.js'

export default {
	name: 'ResumeDraftModal',

	props: {
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
		/** The form's name, above the question. */
		formTitle: { type: String, default: '' },
		/** When the answers were last saved, in words; empty when unknown. */
		savedAt: { type: String, default: '' },
		/** Whether the draft is being discarded. */
		busy: { type: Boolean, default: false },
	},

	emits: ['resume', 'restart'],

	mounted() {
		openDialog(this.$refs.dialog, this.$refs.heading)
	},

	beforeUnmount() {
		closeDialog(this.$refs.dialog)
	},
}
</script>
