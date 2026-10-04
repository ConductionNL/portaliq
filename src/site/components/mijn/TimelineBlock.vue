<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A `timeline` block on a record page: what happened to the open record,
	from its collection's timeline provider, as a Den Haag contact timeline,
	newest first. Loading shows a skeleton, a failed read an alert with
	"Opnieuw proberen", and nothing yet a sentence.
-->
<template>
	<section
		v-if="recordId"
		class="pq-timeline-block"
		:aria-labelledby="headingId"
		data-testid="mijn-timeline-block">
		<component :is="`h${level}`" :id="headingId" class="utrecht-heading-3">
			{{ heading }}
		</component>
		<Skeleton
			v-if="answer === null && !failed"
			:label="tr('Loading')"
			:rows="3" />
		<LoadError
			v-else-if="failed"
			:text="tr('What happened could not be loaded.')"
			:retryLabel="tr('Try again')"
			@retry="load" />
		<EmptyState
			v-else-if="entries.length === 0"
			:text="tr('Nothing has happened yet.')" />
		<ContactTimeline v-else :entries="entries" :tr="tr" :locale="locale" />
	</section>
</template>

<script>
import ContactTimeline from './ContactTimeline.vue'
import EmptyState from './EmptyState.vue'
import LoadError from './LoadError.vue'
import Skeleton from './Skeleton.vue'
import { mijnTranslator } from './rows.js'

/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
 */
export default {
	name: 'TimelineBlock',

	components: { ContactTimeline, EmptyState, LoadError, Skeleton },

	props: {
		/** The normalised block: `collection`, `label?`. */
		block: { type: Object, required: true },
		/** The collection, with its `timeline` declaration. */
		collection: { type: Object, default: null },
		/** The open record. */
		record: { type: Object, default: null },
		/** The shared portal api (`fetchTimeline`). */
		api: { type: Object, default: null },
		/** The heading level. */
		level: { type: Number, default: 2 },
		/** The site translator. */
		t: { type: Function, default: null },
		/** The page language. */
		locale: { type: String, default: 'nl' },
		/** An answer to start from, for a test. */
		initialAnswer: { type: Object, default: null },
	},

	data() {
		return { answer: this.initialAnswer, failed: false }
	},

	computed: {
		/**
		 * @return {(key: string, vars?: object) => string} The translator.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		tr() {
			return mijnTranslator(this.t, this.locale)
		},

		/**
		 * @return {string} The open record's id, or ''.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		recordId() {
			const row = this.record
			return String(row?.id || row?.uuid || row?.['@self']?.id || '')
		},

		/**
		 * @return {string} The block's label, else the app's, else "Wat er is gebeurd".
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		heading() {
			return (
				this.block?.label
				|| this.answer?.label
				|| this.collection?.timeline?.label
				|| this.tr('What happened')
			)
		},

		/**
		 * @return {string} The heading's id.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		headingId() {
			return `pq-timeline-${String(this.block?.collection || '').replace(/[^A-Za-z0-9_-]/g, '')}`
		},

		/**
		 * @return {Array<object>} The entries.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		entries() {
			return Array.isArray(this.answer?.entries) ? this.answer.entries : []
		},
	},

	watch: {
		/**
		 * Another record opened: read its history.
		 *
		 * @return {void}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		recordId() {
			this.load()
		},
	},

	/**
	 * Read the open record's history on arrival.
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
	 */
	created() {
		if (this.answer === null) {
			this.load()
		}
	},

	methods: {
		/**
		 * Read the history; a read that fails says so, it is not "nothing yet".
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
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
				answer = await this.api?.fetchTimeline?.(this.collection, id)
			} catch {
				answer = null
			}
			if (id !== this.recordId) {
				return
			}
			this.failed = !answer || !Array.isArray(answer.entries)
			this.answer = this.failed ? null : answer
		},
	},
}
</script>

<style scoped>
.pq-timeline-block {
	margin-block-end: var(--utrecht-space-block-lg, 1.5rem);
}
</style>
