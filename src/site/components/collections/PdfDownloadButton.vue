<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"Download as PDF" over a collection that opted in with `exportPdf`, and on the
	detail of one of its rows (cases-export-own-data-pdf). The server renders what
	the screen shows; this button only asks and saves the file. A list that is
	too long and any other failure say so in words.
-->
<template>
	<div v-if="collection.exportPdf === true && api" class="pq-pdf" data-testid="pdf-download">
		<button
			type="button"
			class="utrecht-button utrecht-button--secondary-action"
			:disabled="busy"
			data-testid="pdf-download-button"
			@click="download">
			{{ t('Download as PDF') }}
		</button>
		<p
			v-if="message"
			class="utrecht-paragraph"
			role="status"
			data-testid="pdf-download-message">
			{{ message }}
		</p>
	</div>
</template>

<script>
/**
 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t06
 */
export default {
	name: 'PdfDownloadButton',
	props: {
		/** The collection: `exportPdf`, `register`, `schema`, `id`. */
		collection: { type: Object, required: true },
		/** The record id for the detail export; none exports the list. */
		id: { type: [String, Number], default: '' },
		/** The portal api, with `downloadPdf`. */
		api: { type: Object, default: null },
		/** The translator. */
		t: { type: Function, required: true },
	},

	data() {
		return { busy: false, message: '' }
	},

	methods: {
		/**
		 * Ask for the file. A 400 means the list is too long for one PDF.
		 *
		 * @return {Promise<void>} Resolves when the file is saved or the reason is shown.
		 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t07
		 */
		async download() {
			this.busy = true
			this.message = ''
			const result = await this.api.downloadPdf(this.collection, this.id === '' ? undefined : this.id)
			this.busy = false
			if (result && result.ok === true) {
				return
			}

			this.message = result && result.status === 400
				? this.t('This list is too long for one PDF. Filter it first.')
				: this.t('The PDF could not be made. Try again later.')
		},
	},
}
</script>
