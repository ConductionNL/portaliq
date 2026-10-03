<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A `documents` block on a case's record page: the documents the case app
	lets the resident see, as Den Haag file items, read through the case
	screen's own route (so a document is only listed when the case is the
	resident's). Loading shows a skeleton, a failed read an alert with
	"Opnieuw proberen", and none a sentence.
-->
<template>
	<section
		v-if="recordId"
		class="pq-documents-block"
		:aria-labelledby="headingId"
		data-testid="mijn-documents-block">
		<component :is="`h${level}`" :id="headingId" class="utrecht-heading-3">
			{{ heading }}
		</component>
		<Skeleton
			v-if="answer === null && !failed"
			:label="tr('Loading')"
			:rows="2" />
		<LoadError
			v-else-if="failed"
			:text="tr('The documents could not be loaded.')"
			:retryLabel="tr('Try again')"
			@retry="load" />
		<EmptyState
			v-else-if="documents.length === 0"
			:text="tr('There are no documents on this case yet.')" />
		<template v-else>
			<ul class="pq-documents-block__list">
				<FileItem
					v-for="entry in documents"
					:key="entry.id"
					:name="entry.title || entry.id"
					:line="lineOf(entry)"
					:busy="busyId === entry.id"
					@open="openDocument(entry)" />
			</ul>
			<p v-if="openFailed" class="utrecht-paragraph" role="alert">
				{{ tr('The document could not be opened. Try again.') }}
			</p>
		</template>
	</section>
</template>

<script>
import EmptyState from './EmptyState.vue'
import FileItem from './FileItem.vue'
import LoadError from './LoadError.vue'
import Skeleton from './Skeleton.vue'
import { fileLine } from './documents.js'
import { mijnTranslator } from './rows.js'

/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
 */
export default {
	name: 'DocumentsBlock',

	components: { EmptyState, FileItem, LoadError, Skeleton },

	props: {
		/** The normalised block: `collection`, `label?`. */
		block: { type: Object, required: true },
		/** The collection, with its `documents` declaration. */
		collection: { type: Object, default: null },
		/** The open record (the case). */
		record: { type: Object, default: null },
		/** The shared portal api (`fetchCitizenCase`, `downloadCitizenDocument`). */
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
		return {
			answer: this.initialAnswer,
			failed: false,
			busyId: null,
			openFailed: false,
		}
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
		 * @return {string} The open case's id, or ''.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		recordId() {
			const row = this.record
			return String(row?.id || row?.uuid || row?.['@self']?.id || '')
		},

		/**
		 * @return {string} The block's label, else the app's, else "Documenten".
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		heading() {
			return (
				this.block?.label
				|| this.answer?.documentsLabel
				|| this.collection?.documents?.label
				|| this.tr('Documents')
			)
		},

		/**
		 * @return {string} The heading's id.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		headingId() {
			return `pq-documents-${String(this.block?.collection || '').replace(/[^A-Za-z0-9_-]/g, '')}`
		},

		/**
		 * @return {Array<object>} The listed documents.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		documents() {
			return Array.isArray(this.answer?.documents) ? this.answer.documents : []
		},
	},

	watch: {
		/**
		 * Another case opened: read its documents.
		 *
		 * @return {void}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		recordId() {
			this.load()
		},
	},

	/**
	 * Read the open case's documents on arrival.
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
		 * Read the case's documents; a read that fails says so.
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
				answer = await this.api?.fetchCitizenCase?.(this.collection, id)
			} catch {
				answer = null
			}
			if (id !== this.recordId) {
				return
			}
			this.failed = !answer || !Array.isArray(answer.documents)
			this.answer = this.failed ? null : answer
		},

		/**
		 * @param {object} entry A document.
		 * @return {string} Who added it, when, its type and size.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		lineOf(entry) {
			return fileLine(entry, this.tr, this.locale)
		},

		/**
		 * Download one document; a failure says so.
		 *
		 * @param {object} entry A document.
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		async openDocument(entry) {
			this.busyId = entry.id
			this.openFailed = false
			let result
			try {
				result = await this.api?.downloadCitizenDocument?.(
					this.collection,
					this.recordId,
					entry,
				)
			} catch {
				result = null
			}
			this.busyId = null
			this.openFailed = result?.ok !== true
		},
	},
}
</script>

<style scoped>
.pq-documents-block {
	margin-block-end: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-documents-block__list {
	margin: 0;
	padding: 0;
}
</style>
