<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<p
		v-if="!row && !quietWhenEmpty"
		class="utrecht-paragraph pq-detail__empty"
		data-testid="detail-card-empty">
		<em>{{ t('Select an item.') }}</em>
	</p>
	<div
		v-else-if="row"
		class="pq-detail"
		:class="`pq-detail--${layout}`"
		data-testid="detail-card">
		<p v-if="notice" class="utrecht-paragraph pq-detail__notice" role="status">
			{{ notice }}
		</p>
		<!-- The fields as a description list (site-mijn-omgeving-components
		     REQ-SMO-005, design D2). -->
		<DescriptionList :items="facts" itemTestid="detail-card-field" />

		<div
			v-if="collection.filesUpload === true && api"
			class="pq-detail__upload"
			data-testid="detail-card-upload">
			<label class="utrecht-form-label" :for="uploadId">{{
				t('Add an attachment')
			}}</label>
			<input
				:id="uploadId"
				ref="upload"
				class="pq-detail__upload-input"
				type="file"
				:disabled="upload.busy"
				@change="onUpload" />
			<p v-if="upload.busy" class="utrecht-paragraph" role="status">
				{{ t('Loading…') }}
			</p>
			<p v-if="upload.message" class="utrecht-paragraph" role="status">
				{{ upload.message }}
			</p>
		</div>

		<div
			v-if="collection.filesDownload === true && api && files.length > 0"
			class="pq-detail__files"
			data-testid="detail-card-files">
			<h3 class="utrecht-heading-4">
				{{ t('Attachments') }}
			</h3>
			<ul class="utrecht-unordered-list">
				<li
					v-for="file in files"
					:key="file.id"
					class="utrecht-unordered-list__item">
					<button
						type="button"
						class="utrecht-button utrecht-button--subtle"
						:disabled="download.busyId === file.id"
						data-testid="detail-card-download"
						@click="onDownload(file)">
						{{ file.name || t('File {id}', { id: file.id }) }}
					</button>
				</li>
			</ul>
			<p v-if="download.message" class="utrecht-paragraph" role="status">
				{{ download.message }}
			</p>
		</div>

		<SlotHost
			v-if="proposeAction && api"
			name="proposals"
			:action="proposeAction"
			:row="detailRow"
			:api="api"
			:t="t"
			:locale="locale" />

		<ItemList
			v-if="collection.itemList && api"
			:collection="collection"
			:row="detailRow"
			:api="api"
			:t="t" />

		<SlotHost
			name="attachedActions"
			:collection="collection"
			:row="detailRow"
			:api="api"
			:t="t"
			:locale="locale" />

		<TimelineList
			v-if="collection.timeline && timeline !== false"
			:label="collection.timeline.label || ''"
			:entries="timeline ? timeline.entries || [] : null"
			:t="t"
			:locale="locale" />
	</div>
</template>

<script>
import DescriptionList from '../mijn/DescriptionList.vue'
import ItemList from './ItemList.vue'
import SlotHost from './SlotHost.vue'
import TimelineList from './TimelineList.vue'
import { rowNotice } from '../../../shared/rowAction.js'
import { detailFields, formatCell, rowIdOf } from './cells.js'

let uploadCounter = 0

/**
 * One record of a collection (slice b, b4): its fields with their labels, its
 * files with upload and download when the collection opts in, the place for
 * its change proposals and another app's actions, its item list and its
 * timeline.
 *
 * The full single-record read carries the `_files` listing the download list
 * needs; the list projection leaves it out. So the card reads the record once
 * it is selected and again after an upload, while the upload keeps the stable
 * list row as its target.
 *
 * The server checks ownership and the opt-in on every upload and download;
 * these controls are a convenience, not the authority.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-detail-card-must-show-one-record-req-srp-017
 */
export default {
	name: 'DetailCard',

	components: { DescriptionList, ItemList, SlotHost, TimelineList },

	props: {
		/** The collection: `detail`, `columns`, `filesUpload`, `filesDownload`, `itemList`, `timeline`. */
		collection: { type: Object, required: true },
		/** The selected row, or null. */
		row: { type: Object, default: null },
		/** Say nothing while no row is selected: the table above already invites a choice. */
		quietWhenEmpty: { type: Boolean, default: false },
		/** The portal api. */
		api: { type: Object, default: null },
		/** The collection's propose-change action, or null. */
		proposeAction: { type: Object, default: null },
		/** The translator. */
		t: { type: Function, required: true },
		/** The language. */
		locale: { type: String, default: 'nl' },
	},

	data() {
		uploadCounter++
		return {
			full: null,
			timeline: null,
			upload: { busy: false, message: '' },
			download: { busyId: null, message: '' },
			uploadId: `pq-detail-upload-${uploadCounter}`,
		}
	},

	computed: {
		rowId() {
			return rowIdOf(this.row)
		},

		detailRow() {
			return this.full || this.row
		},

		layout() {
			return this.collection.detail?.layout || 'card'
		},

		notice() {
			return this.detailRow ? rowNotice(this.collection, this.detailRow) : ''
		},

		/**
		 * The fields that have a value, formatted for reading.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		fields() {
			const row = this.detailRow || {}
			// A fact without a value says nothing, declared or not: a case
			// with no public team read "Behandeld door" over an empty line.
			// `false` reads "No" and 0 reads "0", so only a value that is
			// really absent is left out.
			return detailFields(this.collection, row)
				.map((field) => ({
					...field,
					text: formatCell(row[field.field], field.render, {
						locale: this.locale,
						t: this.t,
						valueLabels: field.valueLabels,
					}),
				}))
				.filter((field) => field.text !== '')
		},

		/**
		 * The fields as facts for the description list.
		 *
		 * @return {Array<{key: string, label: string, value: string}>}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
		 */
		facts() {
			return this.fields.map((field) => ({
				key: field.field,
				label: field.label,
				value: field.text,
			}))
		},

		files() {
			return Array.isArray(this.detailRow?._files) ? this.detailRow._files : []
		},
	},

	watch: {
		rowId: {
			handler() {
				this.full = null
				this.readFull()
				this.readTimeline()
			},
		},
	},

	mounted() {
		this.readFull()
		this.readTimeline()
	},

	methods: {
		/**
		 * Read the full record, for its file listing.
		 *
		 * @return {Promise<void>}
		 */
		async readFull() {
			const id = this.rowId
			if (
				!id
				|| this.collection.filesDownload !== true
				|| !this.api
				|| typeof this.api.fetchObject !== 'function'
			) {
				return
			}
			const full = await this.api.fetchObject(this.collection, id)
			if (full && this.rowId === id) {
				this.full = full
			}
		},

		/**
		 * Read the record's declared history. `false` leaves the section out.
		 *
		 * @return {Promise<void>}
		 */
		async readTimeline() {
			this.timeline = null
			const id = this.rowId
			if (
				!id
				|| !this.collection.timeline
				|| !this.api
				|| typeof this.api.fetchTimeline !== 'function'
			) {
				return
			}
			const answer = await this.api.fetchTimeline(this.collection, id)
			if (this.rowId === id) {
				this.timeline = answer || false
			}
		},

		/**
		 * Upload the chosen file to the record.
		 *
		 * @param {Event} event The change event.
		 * @return {Promise<void>}
		 */
		async onUpload(event) {
			const file = event?.target?.files && event.target.files[0]
			const id = this.rowId
			if (!file || !id) {
				return
			}
			this.upload = { busy: true, message: '' }
			const result = await this.api.uploadFile(this.collection, id, file)
			this.upload = {
				busy: false,
				message:
					result && result.ok
						? this.t('File added: {name}', {
								name: result.file?.name || file.name,
							})
						: this.t('The upload did not work.'),
			}
			if (this.$refs.upload) {
				this.$refs.upload.value = ''
			}
			if (result && result.ok) {
				await this.readFull()
			}
		},

		/**
		 * Download one file through the portal api, with the resident's bearer.
		 *
		 * @param {object} file The file: `id`, `name`.
		 * @return {Promise<void>}
		 */
		async onDownload(file) {
			const id = this.rowId
			if (!id) {
				return
			}
			this.download = { busyId: file.id, message: '' }
			const result = await this.api.downloadFile(this.collection, id, file)
			this.download = {
				busyId: null,
				message:
					result && result.ok ? '' : this.t('The download did not work.'),
			}
		},
	},
}
</script>

<style scoped>
.pq-detail__fields {
	display: grid;
	grid-template-columns: minmax(8rem, max-content) 1fr;
	gap: var(--utrecht-space-block-sm, 0.5rem) var(--utrecht-space-inline-md, 1rem);
	margin: 0 0 var(--utrecht-space-block-md, 1rem);
}

.pq-detail__field {
	display: contents;
}

.pq-detail__label {
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}

.pq-detail__value {
	margin: 0;
	overflow-wrap: anywhere;
}

.pq-detail__upload,
.pq-detail__files {
	margin-block: var(--utrecht-space-block-md, 1rem);
}

.pq-detail__upload-input {
	display: block;
	margin-block-start: var(--utrecht-space-block-xs, 0.25rem);
}
</style>
