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
	<div
		v-if="collection.exportPdf === true && api"
		class="pq-pdf"
		data-testid="pdf-download">
		<button
			type="button"
			class="utrecht-button utrecht-button--secondary-action"
			:disabled="busy"
			data-testid="pdf-download-button"
			@click="download">
			{{ words.button }}
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
// This component loads on demand, so its words are its own rather than part of the
// site's shared bundle, which has a size budget.
const STRINGS = {
	nl: {
		button: 'Download als pdf',
		tooLong: 'Deze lijst is te lang voor één pdf. Filter hem eerst.',
		failed: 'De pdf kon niet worden gemaakt. Probeer het later opnieuw.',
	},
	en: {
		button: 'Download as PDF',
		tooLong: 'This list is too long for one PDF. Filter it first.',
		failed: 'The PDF could not be made. Try again later.',
	},
}

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
		/** The language, `nl` or `en`. */
		locale: { type: String, default: 'nl' },
	},

	data() {
		return { busy: false, message: '' }
	},

	computed: {
		/**
		 * @return {{button: string, tooLong: string, failed: string}} The words in the page language.
		 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t07
		 */
		words() {
			return STRINGS[
				String(this.locale).toLowerCase().startsWith('en') ? 'en' : 'nl'
			]
		},
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
			const result = await this.api.downloadPdf(
				this.collection,
				this.id === '' ? undefined : this.id,
			)
			this.busy = false
			if (result && result.ok === true) {
				return
			}

			this.message =
				result && result.status === 400
					? this.words.tooLong
					: this.words.failed
		},
	},
}
</script>
