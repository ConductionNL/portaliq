<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The form of one action a case app placed on its case page (a bezwaar, a
	klacht), opened in place under "More actions for this case". It leaves out
	the field that names the case: the server fills it in from the page's case
	and still runs the cross-reference guard. After sending, the form gives way
	to the receipt; a window the server says has closed gives way to its
	sentence.
-->
<template>
	<div
		:id="formId"
		class="pq-case-action-form"
		:data-testid="`case-action-form-${entry.action}`">
		<h3 ref="heading" class="utrecht-heading-3" tabindex="-1">
			{{ entry.label || entry.action }}
		</h3>
		<div
			v-if="received"
			class="utrecht-alert utrecht-alert--ok pq-case-action-form__receipt"
			role="status"
			data-testid="case-action-received">
			<p class="utrecht-paragraph">
				{{ form.successMessage || t('We have received it.') }}
				<template v-if="received.reference">
					{{
						t('Reference {reference}.', {
							reference: received.reference,
						})
					}}
				</template>
			</p>
			<p class="utrecht-paragraph">
				{{ t('You will find it as a case of its own in My cases.') }}
			</p>
		</div>
		<p
			v-else-if="closedText"
			class="utrecht-paragraph pq-case-reason"
			role="alert"
			data-testid="case-action-refused">
			{{ closedText }}
		</p>
		<SchemaForm
			v-else
			:action="form"
			:api="adapter"
			:t="t"
			cancellable
			@cancel="$emit('cancel')"
			@submitted="onSubmitted" />
	</div>
</template>

<script>
import SchemaForm from '../c/SchemaForm.vue'
import {
	caseActionForm,
	caseReference,
	receiptReference,
} from '../../../shared/caseActions.js'

/**
 * @spec openspec/changes/case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-resident-gets-a-receipt-on-the-case-page-req-coc-005
 */
export default {
	name: 'CaseActionForm',

	components: { SchemaForm },

	props: {
		/** One entry of the case answer's `caseActions`. */
		entry: { type: Object, required: true },
		/** The case collection (`register`, `schema`). */
		collection: { type: Object, required: true },
		/** The case id. */
		caseId: { type: String, required: true },
		/** The portal API adapter (`createObjectOnCase`, `uploadFieldFile`, `fetchOptions`). */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
	},

	emits: ['cancel', 'submitted'],

	data() {
		return { received: null, closedText: '' }
	},

	computed: {
		formId() {
			return `pq-case-action-${this.entry.action}`
		},

		/**
		 * @return {object} The action without the field naming the case.
		 * @spec openspec/changes/case-page-objection-and-complaint/tasks.md#t07
		 */
		form() {
			return caseActionForm(this.entry)
		},

		/**
		 * The portal api with its create sent through the case route, so the
		 * file uploads and option lookups of the form stay as they are.
		 *
		 * @return {object}
		 * @spec openspec/changes/case-page-objection-and-complaint/tasks.md#t07
		 */
		adapter() {
			const onCase = caseReference(this.collection, this.caseId)
			return {
				...this.api,
				createObject: async (action, body) => {
					const result = await this.api.createObjectOnCase(
						action,
						onCase,
						body,
					)
					if (
						result
						&& result.status === 409
						&& result.error === 'case_action_closed'
					) {
						this.closedText =
							result.message
							|| this.t('You can no longer do this on this case.')
					}
					return result
				},
			}
		},
	},

	mounted() {
		this.$refs.heading?.focus?.()
	},

	methods: {
		/**
		 * Replace the form with the receipt.
		 *
		 * @param {object|null} object The created record.
		 * @return {void}
		 * @spec openspec/changes/case-page-objection-and-complaint/tasks.md#t07
		 */
		onSubmitted(object) {
			this.received = { reference: receiptReference(object) }
			this.$emit('submitted', object)
		},
	},
}
</script>

<style scoped>
.pq-case-action-form {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-case-action-form__receipt .utrecht-paragraph {
	margin: 0;
}

.pq-case-action-form__receipt .utrecht-paragraph + .utrecht-paragraph {
	margin-block-start: 0.5rem;
}

.pq-case-reason {
	font-style: italic;
}
</style>
