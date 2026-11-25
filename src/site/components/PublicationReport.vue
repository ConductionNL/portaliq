<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"Fout melden" under a publication (publication-error-reports-and-withheld-notices
	REQ-PER-001, REQ-PER-002): a button that opens the report dialog, shown only
	while the portal has error reports on. Focus returns to the button when the
	dialog closes.
-->
<template>
	<div
		v-if="show"
		class="pq-publication-report-trigger"
		data-testid="publication-report">
		<button
			ref="trigger"
			type="button"
			class="utrecht-button utrecht-button--secondary-action"
			aria-haspopup="dialog"
			data-testid="publication-report-open"
			@click="open = true">
			{{ words.trigger }}
		</button>
		<PublicationReportModal
			v-if="open"
			:portal="portal"
			:publication="publication"
			:documents="documents"
			@close="closeDialog" />
	</div>
</template>

<script>
import PublicationReportModal from '../modals/PublicationReportModal.vue'
import { pageLocale } from '../lib/facetLabels.js'
import { offersReport, reportWords } from '../lib/publicationReport.js'

/**
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-error-reports-are-opt-in-and-need-a-named-owner-req-per-001
 */
export default {
	name: 'PublicationReport',

	components: { PublicationReportModal },

	props: {
		/** The site record's `publications`: `{errorReports, withheldNotices}`. */
		publications: { type: Object, default: null },
		/** The serving portal's slug. */
		portal: { type: String, default: '' },
		/** The publication on the page. */
		publication: { type: String, default: '' },
		/** The names of its documents. */
		documents: { type: Array, default: () => [] },
	},

	data() {
		return { open: false }
	},

	computed: {
		/**
		 * @return {boolean} Whether "Fout melden" is offered.
		 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-error-reports-are-opt-in-and-need-a-named-owner-req-per-001
		 */
		show() {
			return offersReport(this.publications, this.publication)
		},

		/**
		 * @return {Record<string, string>} The words in the page language.
		 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
		 */
		words() {
			return reportWords(pageLocale())
		},
	},

	methods: {
		/**
		 * Close the dialog and give focus back to the button.
		 *
		 * @return {void}
		 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
		 */
		closeDialog() {
			this.open = false
			this.$nextTick(() => this.$refs.trigger?.focus?.())
		},
	},
}
</script>

<style scoped>
.pq-publication-report-trigger {
	margin-block: 1.5rem;
}
</style>
