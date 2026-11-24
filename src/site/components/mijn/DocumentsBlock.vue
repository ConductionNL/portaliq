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
		<!-- With `upload` an outlined button beside the heading adds a
		     document through the case screen's route
		     (zuiddrecht-resident-pages-match-the-boards). -->
		<div
			v-if="block.upload === true && api && isCase"
			class="pq-documents-block__head">
			<component :is="`h${level}`" :id="headingId" class="utrecht-heading-3">
				{{ heading }}
			</component>
			<label
				class="utrecht-button utrecht-button--secondary-action pq-documents-block__upload"
				:class="{ 'utrecht-button--disabled': uploading }">
				<input
					type="file"
					class="pq-documents-block__file"
					data-testid="mijn-documents-upload"
					:disabled="uploading"
					@change="addDocument" />
				{{ tr('Add a document') }}
			</label>
		</div>
		<div v-else class="pq-documents-block__title">
			<component :is="`h${level}`" :id="headingId" class="utrecht-heading-3">
				{{ heading }}
			</component>
			<span
				v-if="block.subtitle"
				class="pq-documents-block__subtitle"
				data-testid="mijn-documents-subtitle">
				{{ block.subtitle }}
			</span>
		</div>
		<p
			v-if="uploadNotice"
			class="utrecht-paragraph"
			role="status"
			data-testid="mijn-documents-upload-notice">
			{{ uploadNotice }}
		</p>
		<!-- With `bulkDownload` (and a collection that declares its `bundle`
		     method) one button above the list saves every document together
		     (documents-grouped-per-record). -->
		<p v-if="bundleLabel" class="pq-documents-block__bundle">
			<button
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				data-testid="mijn-documents-bundle"
				:disabled="bundleBusy"
				@click="downloadBundle">
				{{ bundleLabel }}
			</button>
		</p>
		<p
			v-if="bundleFailed"
			class="utrecht-paragraph"
			role="alert"
			data-testid="mijn-documents-bundle-failed">
			{{ tr('The documents could not be downloaded. Try again.') }}
		</p>
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
			:text="block.emptyLabel || tr('There are no documents on this case yet.')" />
		<template v-else>
			<section
				v-for="(group, g) in groups"
				:key="`group-${g}`"
				class="pq-documents-block__group"
				data-testid="mijn-documents-group">
				<component
					:is="`h${Math.min(level + 1, 6)}`"
					v-if="group.heading"
					class="utrecht-heading-4 pq-documents-block__group-heading">
					{{ group.heading }}
				</component>
				<ul class="pq-documents-block__list">
					<FileItem
						v-for="entry in group.entries"
						:key="entry.id"
						:name="entry.title || entry.id"
						:line="lineOf(entry)"
						:busy="busyId === entry.id"
						:isNew="entry.isNew === true"
						:newLabel="tr('New')"
						:status="
							typeof entry.status === 'string' ? entry.status : ''
						"
						:statusState="stateOf(entry)"
						@open="openDocument(entry)" />
				</ul>
			</section>
			<p
				v-if="block.note"
				class="utrecht-paragraph pq-documents-block__note"
				data-testid="mijn-documents-note">
				{{ block.note }}
			</p>
		</template>
		<!-- `extraGroups`: media for the signed-in person, each a group of its
		     own after the provider's; OpenRegister decides which media show
		     (documents-grouped-per-record). A group with nothing is left out. -->
		<section
			v-for="(media, m) in shownMediaGroups"
			:key="`media-${m}`"
			class="pq-documents-block__group"
			data-testid="mijn-documents-media-group">
			<component
				:is="`h${Math.min(level + 1, 6)}`"
				class="utrecht-heading-4 pq-documents-block__group-heading">
				{{ media.label }}
			</component>
			<ul class="pq-documents-block__list">
				<FileItem
					v-for="entry in media.entries"
					:key="entry.id"
					:name="entry.title || entry.id"
					:line="paperLineOf(entry)"
					:busy="busyId === `${m}:${entry.id}`"
					@open="openMedia(media, m, entry)" />
			</ul>
		</section>
		<p v-if="openFailed" class="utrecht-paragraph" role="alert">
			{{ tr('The document could not be opened. Try again.') }}
		</p>
	</section>
</template>

<script>
import EmptyState from './EmptyState.vue'
import FileItem from './FileItem.vue'
import LoadError from './LoadError.vue'
import Skeleton from './Skeleton.vue'
import { fileLine, groupDocuments, paperLine, statusState } from './documents.js'
import { mijnTranslator } from './rows.js'

/**
 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
 */
export default {
	name: 'DocumentsBlock',

	components: { EmptyState, FileItem, LoadError, Skeleton },

	// The portal's organisation type label, from the site shell (App.vue);
	// none reads "de organisatie" (portal-identity-from-the-admin REQ-PIA-003).
	inject: { siteOrganisationType: { default: () => () => '' } },

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
		/** Media groups to start from, for a test: `[{ label, register, schema, entries }]`. */
		initialMediaGroups: { type: Array, default: null },
	},

	data() {
		return {
			answer: this.initialAnswer,
			failed: false,
			busyId: null,
			openFailed: false,
			uploading: false,
			uploadNotice: '',
			mediaGroups: this.initialMediaGroups || [],
			bundleBusy: false,
			bundleFailed: false,
		}
	},

	computed: {
		/**
		 * Whether the collection is a case collection, read through the case
		 * screen's route; any other collection with `documents` reads its own.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t4
		 */
		isCase() {
			return this.collection?.kind === 'cases'
		},

		/**
		 * @return {(key: string, vars?: object) => string} The translator.
		 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		tr() {
			return mijnTranslator(this.t, this.locale)
		},

		/**
		 * @return {string} The open case's id, or ''.
		 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		recordId() {
			const row = this.record
			return String(row?.id || row?.uuid || row?.['@self']?.id || '')
		},

		/**
		 * @return {string} The block's label, else the app's, else "Documenten".
		 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
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
		 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-a-documents-block-for-each-record-shows-one-group-per-record
		 */
		headingId() {
			// One block per record draws several headings: each its own id.
			const own = this.block?.eachRecord === true ? `-${this.recordId}` : ''
			return `pq-documents-${`${this.block?.collection || ''}${own}`.replace(/[^A-Za-z0-9_-]/g, '')}`
		},

		/**
		 * @return {Array<object>} The listed documents.
		 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		groups() {
			return groupDocuments(this.documents, this.block?.groupBy)
		},

		documents() {
			return Array.isArray(this.answer?.documents) ? this.answer.documents : []
		},

		/**
		 * @return {Array<object>} The media groups that list something.
		 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-2
		 */
		shownMediaGroups() {
			return this.mediaGroups.filter(
				(group) => Array.isArray(group?.entries) && group.entries.length > 0,
			)
		},

		/**
		 * @return {string} The bundle button's words, or '' when the block has none.
		 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-2
		 */
		bundleLabel() {
			const label = this.block?.bulkDownload?.label
			return typeof label === 'string' && this.api ? label : ''
		},
	},

	watch: {
		/**
		 * Another case opened: read its documents.
		 *
		 * @return {void}
		 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		recordId() {
			this.load()
		},
	},

	/**
	 * Read the open case's documents on arrival.
	 *
	 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
	 */
	created() {
		if (this.answer === null) {
			this.load()
		}
		if (this.initialMediaGroups === null) {
			this.loadMediaGroups()
		}
	},

	methods: {
		/**
		 * Read the case's documents; a read that fails says so.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
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
				// A case reads its documents through the case screen's route;
				// any other collection that declares `documents` through its own
				// (site-member-voting-record-and-confidential-papers, T4).
				answer = this.isCase
					? await this.api?.fetchCitizenCase?.(
							this.collection,
							id,
							this.record?._mandate?.id || '',
						)
					: await this.api?.fetchCollectionDocuments?.(this.collection, id)
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
		 * Read every extra group's media; a group whose read fails lists
		 * nothing and is left out.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
		 */
		async loadMediaGroups() {
			const declared = Array.isArray(this.block?.extraGroups) ? this.block.extraGroups : []
			if (declared.length === 0 || !this.api?.fetchMediaGroup) {
				return
			}
			this.mediaGroups = await Promise.all(
				declared.map(async (group) => {
					let entries
					try {
						entries = await this.api.fetchMediaGroup(group)
					} catch {
						entries = null
					}
					return { ...group, entries: Array.isArray(entries) ? entries : [] }
				}),
			)
		},

		/**
		 * @param {object} entry A medium.
		 * @return {string} Its day, type and size.
		 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-2
		 */
		paperLineOf(entry) {
			return paperLine(entry, this.locale)
		},

		/**
		 * Download one medium of an extra group; a failure says so.
		 *
		 * @param {object} group The extra group.
		 * @param {number} index Its place among the shown groups.
		 * @param {object} entry The medium.
		 * @return {Promise<void>}
		 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
		 */
		async openMedia(group, index, entry) {
			this.busyId = `${index}:${entry.id}`
			this.openFailed = false
			let result
			try {
				result = await this.api?.downloadMediaGroupFile?.(group, entry)
			} catch {
				result = null
			}
			this.busyId = null
			this.openFailed = result?.ok !== true
		},

		/**
		 * Save the bundle of every document; a failure says so.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-2
		 */
		async downloadBundle() {
			this.bundleBusy = true
			this.bundleFailed = false
			let result
			try {
				result = await this.api?.downloadDocumentsBundle?.(
					this.collection,
					this.recordId,
					this.bundleLabel,
				)
			} catch {
				result = null
			}
			this.bundleBusy = false
			this.bundleFailed = result?.ok !== true
		},

		/**
		 * @param {object} entry A document.
		 * @return {string} Who added it, when, its type and size.
		 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-a-documents-block-for-each-record-shows-one-group-per-record
		 */
		lineOf(entry) {
			// The provider's own line is shown as written; a paper that is not
			// a case's names no sender.
			if (typeof entry?.meta === 'string' && entry.meta !== '') {
				return entry.meta
			}
			return this.isCase
				? fileLine(entry, this.tr, this.locale, this.siteOrganisationType())
				: paperLine(entry, this.locale)
		},

		/**
		 * @param {object} entry A document.
		 * @return {string} The state of its status pill.
		 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-2
		 */
		stateOf(entry) {
			return statusState(entry)
		},

		/**
		 * Download one document; a failure says so.
		 *
		 * @param {object} entry A document.
		 * @return {Promise<void>}
		 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		async openDocument(entry) {
			this.busyId = entry.id
			this.openFailed = false
			let result
			try {
				const download = this.isCase
					? this.api?.downloadCitizenDocument
					: this.api?.downloadCollectionDocument
				result = await download?.(this.collection, this.recordId, entry)
			} catch {
				result = null
			}
			this.busyId = null
			this.openFailed = result?.ok !== true
		},

		/**
		 * Add a document to the case through the case screen's route; the
		 * list is read again on success, a failure says so.
		 *
		 * @param {Event} event The file input's change event.
		 * @return {Promise<void>}
		 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
		 */
		async addDocument(event) {
			const file = event?.target?.files?.[0]
			if (!file || !this.recordId) {
				return
			}
			this.uploading = true
			this.uploadNotice = ''
			let result
			try {
				result = await this.api?.addCitizenDocument?.(
					this.collection,
					this.recordId,
					file,
				)
			} catch {
				result = null
			}
			this.uploading = false
			if (event.target) {
				event.target.value = ''
			}
			if (result?.ok === true) {
				this.uploadNotice = this.tr('{name} has been added to your case.', {
					name: result.document?.name || file.name,
				})
				await this.load()
				return
			}
			this.uploadNotice =
				result?.message
				|| this.tr('The document could not be added. Try again.')
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

/* The heading with the upload button beside it; the file input stays
   reachable by keyboard behind the button. */
.pq-documents-block__head {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: center;
	gap: 0.75rem;
	margin-block-end: var(--utrecht-space-block-sm, 0.5rem);
}

.pq-documents-block__head > * {
	margin: 0;
}

/* The heading with its subtitle on one baseline ("Vera  Groep 7"). */
.pq-documents-block__title {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	column-gap: 0.875rem;
}

.pq-documents-block__subtitle {
	color: var(--nldesign-color-text-muted, inherit);
}

.pq-documents-block__upload {
	position: relative;
	cursor: pointer;
}

.pq-documents-block__file {
	position: absolute;
	inset: 0;
	inline-size: 100%;
	block-size: 100%;
	opacity: 0;
	cursor: pointer;
}

.pq-documents-block__upload:has(.pq-documents-block__file:focus-visible) {
	outline: var(--utrecht-focus-outline-width, 2px)
		var(--utrecht-focus-outline-style, solid)
		var(--utrecht-focus-outline-color, currentcolor);
	outline-offset: 2px;
}
</style>
