<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"Fout melden" (publication-error-reports-and-withheld-notices REQ-PER-002):
	which document, what is wrong, and optionally an address for a reply. It
	posts publication, document and description to the report route without a
	cookie, and says plainly whether the report went. Escape closes it.
-->
<template>
	<div
		class="pq-publication-report"
		role="dialog"
		aria-modal="true"
		:aria-labelledby="headingId"
		data-testid="publication-report-dialog"
		@keydown.esc="$emit('close')">
		<h2 :id="headingId" ref="heading" class="utrecht-heading-2" tabindex="-1">
			{{ words.title }}
		</h2>

		<template v-if="sent">
			<p
				class="utrecht-paragraph"
				role="status"
				data-testid="publication-report-sent">
				{{ words.sent }}
			</p>
			<button
				type="button"
				class="utrecht-button utrecht-button--primary-action"
				data-testid="publication-report-close"
				@click="$emit('close')">
				{{ words.close }}
			</button>
		</template>

		<form
			v-else
			novalidate
			data-testid="publication-report-form"
			@submit.prevent="submit">
			<p class="utrecht-paragraph">{{ words.intro }}</p>

			<div v-if="documents.length > 0" class="pq-publication-report__field">
				<label
					class="utrecht-form-label"
					for="pq-publication-report-document"
					>{{ words.document }}</label
				>
				<select
					id="pq-publication-report-document"
					v-model="form.document"
					class="utrecht-select"
					data-testid="publication-report-document">
					<option value="">{{ words.wholePublication }}</option>
					<option v-for="name in documents" :key="name" :value="name">
						{{ name }}
					</option>
				</select>
			</div>

			<div class="pq-publication-report__field">
				<label
					class="utrecht-form-label"
					for="pq-publication-report-description"
					>{{ words.description }}</label
				>
				<p
					id="pq-publication-report-description-hint"
					class="utrecht-form-field-description">
					{{ words.descriptionHint }}
				</p>
				<textarea
					id="pq-publication-report-description"
					v-model="form.description"
					class="utrecht-textarea"
					rows="5"
					:maxlength="max"
					required
					aria-describedby="pq-publication-report-description-hint"
					:aria-invalid="
						problem === 'empty' || problem === 'tooLong'
							? 'true'
							: 'false'
					"
					data-testid="publication-report-description" />
			</div>

			<div class="pq-publication-report__field">
				<label
					class="utrecht-form-label"
					for="pq-publication-report-email"
					>{{ words.email }}</label
				>
				<p
					id="pq-publication-report-email-hint"
					class="utrecht-form-field-description">
					{{ words.emailHint }}
				</p>
				<input
					id="pq-publication-report-email"
					v-model="form.contactEmail"
					class="utrecht-textbox"
					type="email"
					autocomplete="email"
					aria-describedby="pq-publication-report-email-hint"
					:aria-invalid="problem === 'badEmail' ? 'true' : 'false'"
					data-testid="publication-report-email" />
			</div>

			<p
				v-if="problem"
				class="utrecht-form-field-error-message"
				role="alert"
				data-testid="publication-report-problem">
				{{ words[problem] }}
			</p>

			<div class="pq-publication-report__actions">
				<button
					type="submit"
					class="utrecht-button utrecht-button--primary-action"
					:disabled="sending"
					data-testid="publication-report-send">
					{{ sending ? words.sending : words.send }}
				</button>
				<button
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					data-testid="publication-report-cancel"
					@click="$emit('close')">
					{{ words.cancel }}
				</button>
			</div>
		</form>
	</div>
</template>

<script>
import { resolveApiBase } from '../lib/contentApi.js'
import { pageLocale } from '../lib/facetLabels.js'
import {
	failureKey,
	formProblem,
	MAX_DESCRIPTION,
	reportsUrlFrom,
	reportWords,
	sendReport,
} from '../lib/publicationReport.js'

/**
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
 */
export default {
	name: 'PublicationReportModal',

	props: {
		/** The serving portal's slug. */
		portal: { type: String, default: '' },
		/** The publication the report is about. */
		publication: { type: String, required: true },
		/** The names of the publication's documents, to choose one. */
		documents: { type: Array, default: () => [] },
		/** The send, replaceable in tests: (url, report) => {ok, status}. */
		send: { type: Function, default: null },
	},

	emits: ['close'],

	data() {
		return {
			form: { document: '', description: '', contactEmail: '' },
			problem: '',
			sending: false,
			sent: false,
		}
	},

	computed: {
		/**
		 * @return {Record<string, string>} The words in the page language.
		 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
		 */
		words() {
			return reportWords(pageLocale())
		},

		/**
		 * @return {string} An id for the heading that names the dialog.
		 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
		 */
		headingId() {
			return 'pq-publication-report-title'
		},

		/**
		 * @return {number} The longest description.
		 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
		 */
		max() {
			return MAX_DESCRIPTION
		},
	},

	mounted() {
		this.$refs.heading?.focus?.()
	},

	methods: {
		/**
		 * @return {string} The report route next to the content API.
		 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
		 */
		reportsUrl() {
			return reportsUrlFrom(resolveApiBase())
		},

		/**
		 * Check the form, then post the report and say what happened.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
		 */
		async submit() {
			this.problem = formProblem(this.form)
			if (this.problem !== '' || this.sending) {
				return
			}

			this.sending = true
			const send = this.send || sendReport
			const answer = await send(this.reportsUrl(), {
				portal: this.portal,
				publication: this.publication,
				document: this.form.document,
				description: this.form.description,
				contactEmail: this.form.contactEmail,
			})
			this.sending = false
			if (answer.ok === true) {
				this.sent = true
				return
			}
			this.problem = failureKey(answer.status)
		},
	},
}
</script>

<style scoped>
.pq-publication-report {
	margin-block: 1rem;
	padding: 1.5rem;
	border: 1px solid var(--nldesign-color-border-dark, currentcolor);
	border-radius: var(--utrecht-border-radius-md, 0.75rem);
	background: var(--nldesign-color-surface, Canvas);
}

.pq-publication-report__field {
	margin-block: 1rem;
}

.pq-publication-report__field textarea,
.pq-publication-report__field input,
.pq-publication-report__field select {
	inline-size: 100%;
	box-sizing: border-box;
}

.pq-publication-report__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 0.75rem;
}
</style>
