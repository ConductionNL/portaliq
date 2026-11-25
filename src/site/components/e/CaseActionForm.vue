<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The form of a create action filed from the case page
	(case-page-objection-and-complaint REQ-COC-004/005): a bezwaar or a klacht
	about this case. It asks the action's fields without the case, which the
	server fills in from `onCase`. Focus moves to the heading when it opens;
	cancel sends nothing. A sent form is replaced by the receipt, and a closed
	window shows the server's sentence.
-->
<template>
	<div class="pq-case-action-form" :data-testid="`case-action-form-${form.id}`">
		<template v-if="receipt.length === 0">
			<h3 ref="heading" class="utrecht-heading-3" tabindex="-1">
				{{ label }}
			</h3>
			<p
				v-if="refusal"
				class="utrecht-paragraph pq-case-reason"
				role="alert"
				data-testid="case-action-closed">
				{{ refusal }}
			</p>
			<SchemaForm
				:action="form"
				:api="api"
				:t="t"
				:send="sendOnCase"
				cancellable
				@cancel="$emit('cancel')"
				@submitted="onSubmitted" />
		</template>
		<div
			v-else
			class="pq-case-action-form__receipt"
			role="status"
			data-testid="case-action-receipt">
			<p v-for="line in receipt" :key="line" class="utrecht-paragraph">
				{{ line }}
			</p>
		</div>
	</div>
</template>

<script>
import SchemaForm from '../c/SchemaForm.vue'
import { closedRefusal, receiptLines } from './caseActions.js'

export default {
	name: 'CaseActionForm',

	components: { SchemaForm },

	props: {
		/** The action as the server shaped it for this form, without the case field. */
		form: { type: Object, required: true },
		/** The button's label, the form's heading. */
		label: { type: String, required: true },
		/** The case, `<register>/<schema>/<id>`. */
		target: { type: String, required: true },
		/** The portal API adapter (`createObjectOnCase`). */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
	},

	emits: ['cancel', 'submitted'],

	data() {
		return {
			refusal: '',
			receipt: [],
		}
	},

	mounted() {
		this.$refs.heading?.focus()
	},

	methods: {
		/**
		 * Send the answers with the case, and keep the server's sentence when
		 * the window has closed since the page was opened.
		 *
		 * @param {object} body The answers.
		 * @return {Promise<object>} The create's result.
		 *
		 * @spec openspec/changes/archive/2026-11-24-case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-window-is-computed-on-the-server-req-coc-003
		 */
		async sendOnCase(body) {
			const result = await this.api.createObjectOnCase(
				this.form,
				body,
				this.target,
			)
			this.refusal = closedRefusal(result)
			return result
		},

		/**
		 * Replace the form with the receipt.
		 *
		 * @param {object|null} object The created object.
		 *
		 * @spec openspec/changes/archive/2026-11-24-case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-resident-gets-a-receipt-on-the-case-page-req-coc-005
		 */
		onSubmitted(object) {
			this.receipt = receiptLines(this.t, object)
			this.$emit('submitted', object)
		},
	},
}
</script>
