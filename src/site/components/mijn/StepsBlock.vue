<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A `steps` block on a case's record page: where the open case stands, from
	its collection's steps provider, under the provider's label. Loading
	shows a skeleton, a failed read an alert with "Opnieuw proberen", and an
	answer without steps a sentence.
-->
<template>
	<section
		v-if="recordId"
		class="pq-steps-block"
		:aria-labelledby="heading ? headingId : undefined"
		data-testid="mijn-steps-block">
		<component
			:is="`h${level}`"
			v-if="heading"
			:id="headingId"
			class="utrecht-heading-3">
			{{ heading }}
		</component>
		<Skeleton
			v-if="answer === null && !failed"
			:label="tr('Loading')"
			:rows="3" />
		<LoadError
			v-else-if="failed"
			:text="tr('Where your case stands could not be loaded.')"
			:retryLabel="tr('Try again')"
			@retry="load" />
		<EmptyState
			v-else-if="steps.length === 0"
			:text="tr('There are no steps to show yet.')" />
		<ProcessSteps
			v-else
			:steps="steps"
			:display="block.display === 'bars' ? 'bars' : 'list'"
			:tr="tr"
			:locale="locale"
			:today="today" />
	</section>
</template>

<script>
import EmptyState from './EmptyState.vue'
import LoadError from './LoadError.vue'
import ProcessSteps from './ProcessSteps.vue'
import Skeleton from './Skeleton.vue'
import { mijnTranslator } from './rows.js'

/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-process-steps-must-say-where-a-case-stands-in-order-req-smo-003
 */
export default {
	name: 'StepsBlock',

	components: { EmptyState, LoadError, ProcessSteps, Skeleton },

	props: {
		/** The normalised block: `collection`, `label?`. */
		block: { type: Object, required: true },
		/** The collection, with its `steps` declaration. */
		collection: { type: Object, default: null },
		/** The open record (the case). */
		record: { type: Object, default: null },
		/** The shared portal api (`fetchSteps`). */
		api: { type: Object, default: null },
		/** The heading level. */
		level: { type: Number, default: 2 },
		/** The site translator. */
		t: { type: Function, default: null },
		/** The page language. */
		locale: { type: String, default: 'nl' },
		/** Today; a test passes a fixed day. */
		today: { type: Date, default: null },
		/** An answer to start from, for a test. */
		initialAnswer: { type: Object, default: null },
	},

	data() {
		return { answer: this.initialAnswer, failed: false }
	},

	computed: {
		/**
		 * @return {(key: string, vars?: object) => string} The translator.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-process-steps-must-say-where-a-case-stands-in-order-req-smo-003
		 */
		tr() {
			return mijnTranslator(this.t, this.locale)
		},

		/**
		 * @return {string} The open case's id, or ''.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-process-steps-must-say-where-a-case-stands-in-order-req-smo-003
		 */
		recordId() {
			const row = this.record
			return String(row?.id || row?.uuid || row?.['@self']?.id || '')
		},

		/**
		 * @return {string} The block's label, else the provider's.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-process-steps-must-say-where-a-case-stands-in-order-req-smo-003
		 */
		heading() {
			return (
				this.block?.label
				|| this.answer?.label
				|| this.collection?.steps?.label
				|| ''
			)
		},

		/**
		 * @return {string} The heading's id.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-process-steps-must-say-where-a-case-stands-in-order-req-smo-003
		 */
		headingId() {
			return `pq-steps-${String(this.block?.collection || '').replace(/[^A-Za-z0-9_-]/g, '')}`
		},

		/**
		 * @return {Array<object>} The steps.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-process-steps-must-say-where-a-case-stands-in-order-req-smo-003
		 */
		steps() {
			return Array.isArray(this.answer?.steps) ? this.answer.steps : []
		},
	},

	watch: {
		/**
		 * Another case opened: read its steps.
		 *
		 * @return {void}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-process-steps-must-say-where-a-case-stands-in-order-req-smo-003
		 */
		recordId() {
			this.load()
		},
	},

	/**
	 * Read the open case's steps on arrival.
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-process-steps-must-say-where-a-case-stands-in-order-req-smo-003
	 */
	created() {
		if (this.answer === null) {
			this.load()
		}
	},

	methods: {
		/**
		 * Read the steps; a read that fails says so, it is not "no steps".
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-process-steps-must-say-where-a-case-stands-in-order-req-smo-003
		 */
		async load() {
			const id = this.recordId
			if (!id || !this.collection) {
				return
			}
			this.answer = null
			this.failed = false
			let answer
			try {
				answer = await this.api?.fetchSteps?.(this.collection, id)
			} catch {
				answer = null
			}
			if (id !== this.recordId) {
				return
			}
			this.failed = !answer || !Array.isArray(answer.steps)
			this.answer = this.failed ? null : answer
		},
	},
}
</script>

<style scoped>
.pq-steps-block {
	margin-block-end: var(--utrecht-space-block-lg, 1.5rem);
}
</style>
