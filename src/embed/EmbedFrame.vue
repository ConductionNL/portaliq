<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The embed frame (site-reaches-portal-parity REQ-SRP-047), ported from
	the React portal's EmbeddedForm.jsx: the form, or the reason there is
	not one, in words. A visitor meeting a blank rectangle on a municipality's
	website cannot tell whether the form is broken, still loading, or not for
	them, so every refusal has a sentence (src/shared/embedCopy.js).

	The form itself is EmbedForm.vue, the mount point slice c's SchemaForm
	replaces.
-->
<template>
	<div class="pq-embed">
		<div
			v-if="refusal !== null"
			class="pq-embed__refusal utrecht-alert"
			data-testid="embed-refusal"
			role="status">
			<p class="utrecht-paragraph">
				{{ t(refusal) }}
			</p>
			<p v-if="payload.portalUrl" class="utrecht-paragraph">
				<a
					class="utrecht-link utrecht-link--html-a"
					:href="payload.portalUrl"
					target="_top"
					>{{ t('Continue on our own portal') }}</a
				>
			</p>
		</div>

		<div
			v-else-if="reference !== null"
			class="pq-embed__confirmation utrecht-alert"
			data-testid="embed-confirmation"
			role="status">
			<p class="utrecht-paragraph">
				{{ confirmationText }}
			</p>
			<p class="utrecht-paragraph" data-testid="embed-reference">
				{{ t('Your reference: {reference}', { reference }) }}
			</p>
		</div>

		<template v-else>
			<ul
				v-if="messages.length > 0"
				class="pq-embed__errors utrecht-form-field-error-message"
				data-testid="embed-errors"
				role="alert">
				<li v-for="(message, index) in messages" :key="index">
					{{ message }}
				</li>
			</ul>
			<EmbedForm
				:fields="fields"
				:fieldErrors="fieldErrors"
				:busy="busy"
				:t="t"
				@submit="send" />
		</template>
	</div>
</template>

<script>
import EmbedForm from './EmbedForm.vue'
import { submissionErrors, submitAnswers } from './frame.js'
import { refusalKey } from './strings.js'

export default {
	name: 'EmbedFrame',

	components: { EmbedForm },

	props: {
		/** The boot payload from the frame route: `{route, fields, settings}` or `{refused, portalUrl}`. */
		payload: {
			type: Object,
			default: () => ({}),
		},

		/** The frame's submit route; empty means the form cannot be sent. */
		submitUrl: {
			type: String,
			default: '',
		},

		/** The translator `t(key, vars)`. */
		t: {
			type: Function,
			required: true,
		},

		/** Sends the answers (test seam); defaults to a POST to `submitUrl`. */
		submitter: {
			type: Function,
			default: null,
		},
	},

	data() {
		return {
			reference: null,
			busy: false,
			messages: [],
			fieldErrors: {},
		}
	},

	computed: {
		/**
		 * The key of the refusal sentence, or null when there is a form.
		 *
		 * @return {string|null}
		 */
		refusal() {
			return refusalKey(this.payload)
		},

		/**
		 * The form's fields.
		 *
		 * @return {Array<object>}
		 */
		fields() {
			return Array.isArray(this.payload?.fields) ? this.payload.fields : []
		},

		/**
		 * The portal's own confirmation, or the standard one.
		 *
		 * @return {string}
		 */
		confirmationText() {
			return (
				this.payload?.settings?.confirmationText
				|| this.t('Thank you, we have received your application.')
			)
		},
	},

	methods: {
		/**
		 * Send the answers and show the reference, or say why not.
		 *
		 * 🔴 A REFUSED SUBMISSION SAYS SO. A form that quietly does nothing
		 * on submit is one a visitor presses four times and then leaves,
		 * believing they have applied.
		 *
		 * @param {object} answers The answers.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-embed-frame-must-render-its-form-from-a-small-entry-req-srp-047
		 */
		async send(answers) {
			this.busy = true
			this.messages = []
			this.fieldErrors = {}
			try {
				const result = this.submitter
					? await this.submitter(answers)
					: await submitAnswers({
							submitUrl: this.submitUrl,
							route: this.payload?.route,
							answers,
						})
				if (result?.reference) {
					this.reference = String(result.reference)
					return
				}

				const errors = submissionErrors(result)
				this.fieldErrors = errors.fields
				this.messages =
					errors.messages.length > 0
						? errors.messages
						: [this.t('Your application could not be sent.')]
			} catch {
				this.messages = [
					this.t(
						'Your application could not be sent. Please try again later.',
					),
				]
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.pq-embed {
	padding: var(--utrecht-space-block-md, 1rem);
}

.pq-embed__errors {
	margin-block-end: var(--utrecht-space-block-md, 1rem);
}
</style>
