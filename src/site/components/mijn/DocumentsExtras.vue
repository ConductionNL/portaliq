<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	What a documents block may add (documents-grouped-per-record), loaded on
	demand so the site entry stays in its budget:
	- `part="bundle"`: one button above the list that saves every document
	  together, through the collection's declared `bundle` method;
	- `part="media"`: a group per `extraGroups` entry, the media OpenRegister
	  lets the signed-in person read. A group with nothing is left out.
-->
<template>
	<div v-if="part === 'bundle'" class="pq-documents-extras">
		<p class="pq-documents-extras__bundle">
			<button
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				data-testid="mijn-documents-bundle"
				:disabled="busy"
				@click="downloadBundle">
				{{ block.bulkDownload.label }}
			</button>
		</p>
		<p
			v-if="failed"
			class="utrecht-paragraph"
			role="alert"
			data-testid="mijn-documents-bundle-failed">
			{{ bundleFailedText }}
		</p>
	</div>
	<div v-else class="pq-documents-extras">
		<section
			v-for="(media, m) in shownGroups"
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
					:line="lineOf(entry)"
					:busy="busy === `${m}:${entry.id}`"
					@open="openMedia(media, m, entry)" />
			</ul>
		</section>
		<p v-if="failed" class="utrecht-paragraph" role="alert">
			{{ tr('The document could not be opened. Try again.') }}
		</p>
	</div>
</template>

<script>
import FileItem from './FileItem.vue'
import { paperLine } from './documents.js'
import { mijnTranslator } from './rows.js'

/**
 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-2
 */
export default {
	name: 'DocumentsExtras',

	components: { FileItem },

	// The documents block hands over all its props; only these are read.
	inheritAttrs: false,

	props: {
		/** `bundle` (the button) or `media` (the extra groups). */
		part: { type: String, default: 'media' },
		/** The normalised documents block. */
		block: { type: Object, required: true },
		/** The block's collection. */
		collection: { type: Object, default: null },
		/** The open record. */
		record: { type: Object, default: null },
		/** The shared portal api. */
		api: { type: Object, default: null },
		/** The block's heading level. */
		level: { type: Number, default: 2 },
		/** The site translator. */
		t: { type: Function, default: null },
		/** The page language. */
		locale: { type: String, default: 'nl' },
		/** Media groups to start from, for a test: `[{ label, register, schema, entries }]`. */
		initialGroups: { type: Array, default: null },
	},

	data() {
		return { groups: this.initialGroups || [], busy: false, failed: false }
	},

	computed: {
		/**
		 * @return {(key: string, vars?: object) => string} The translator.
		 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-2
		 */
		tr() {
			return mijnTranslator(this.t, this.locale)
		},

		/**
		 * @return {string} The sentence when the bundle could not be saved.
		 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-2
		 */
		bundleFailedText() {
			const key = 'The documents could not be downloaded. Try again.'
			const site = typeof this.t === 'function' ? this.t(key) : key
			if (site !== key || String(this.locale).startsWith('en')) {
				return site
			}
			return 'De documenten konden niet worden gedownload. Probeer het opnieuw.'
		},

		/**
		 * @return {Array<object>} The media groups that list something.
		 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-2
		 */
		shownGroups() {
			return this.groups.filter(
				(group) => Array.isArray(group?.entries) && group.entries.length > 0,
			)
		},
	},

	/**
	 * Read the media groups on arrival.
	 *
	 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
	 */
	created() {
		if (this.part === 'media' && this.initialGroups === null) {
			this.loadGroups()
		}
	},

	methods: {
		/**
		 * Read every extra group's media; a group whose read fails lists
		 * nothing and is left out.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
		 */
		async loadGroups() {
			const declared = Array.isArray(this.block?.extraGroups) ? this.block.extraGroups : []
			if (declared.length === 0 || !this.api) {
				return
			}
			this.groups = await Promise.all(
				declared.map(async (group) => {
					let entries
					try {
						entries = await (await this.calls()).fetchMediaGroup(group)
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
		lineOf(entry) {
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
			this.busy = `${index}:${entry.id}`
			this.failed = false
			const result = await this.attempt(async () => (await this.calls()).downloadMediaGroupFile(group, entry))
			this.busy = false
			this.failed = result?.ok !== true
		},

		/**
		 * Save the bundle of every document; a failure says so.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-2
		 */
		async downloadBundle() {
			this.busy = true
			this.failed = false
			const result = await this.attempt(async () =>
				(await this.calls()).downloadDocumentsBundle(this.collection, this.recordId(), this.block.bulkDownload.label),
			)
			this.busy = false
			this.failed = result?.ok !== true
		},

		/**
		 * @return {string} The open record's id, or ''.
		 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-2
		 */
		recordId() {
			const row = this.record
			return String(row?.id || row?.uuid || row?.['@self']?.id || '')
		},

		/**
		 * The extras' api calls over the portal api's `request` door, loaded
		 * on demand; an api without that door is taken as the calls (a test).
		 *
		 * @return {Promise<object>} The calls.
		 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-2
		 */
		async calls() {
			if (typeof this.api?.request !== 'function') {
				return this.api
			}
			const { documentsExtrasApi } = await import('../../../shared/documentsExtrasApi.js')
			return documentsExtrasApi(this.api)
		},

		/**
		 * @param {() => Promise<object>} call The api call.
		 * @return {Promise<object|null>} Its answer, or null when it throws.
		 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-2
		 */
		async attempt(call) {
			try {
				return await call()
			} catch {
				return null
			}
		},
	},
}
</script>

<style scoped>
.pq-documents-extras__bundle {
	margin-block: 0 var(--utrecht-space-block-sm, 0.5rem);
}

.pq-documents-block__list {
	margin: 0;
	padding: 0;
}
</style>
