<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The data section of the publication page (dataset-views-on-the-public-page,
  REQ-DSV-003): each published table's views under the table's title, in
  their saved order. Loaded on demand, only for a publication with views.
-->
<template>
	<section
		v-if="tables.length > 0"
		class="pq-detail__datasets"
		data-testid="publication-dataset-views">
		<h2 class="utrecht-heading-2">
			{{ t('Data') }}
		</h2>
		<section
			v-for="table in tables"
			:key="table.id || table.title"
			class="pq-detail__dataset"
			:data-table="table.id">
			<h3 v-if="table.title" class="utrecht-heading-3">
				{{ table.title }}
			</h3>
			<PublicationDatasetView
				v-for="view in table.views"
				:key="view.id"
				:view="view"
				:rowsUrl="table.rowsUrl"
				:t="t"
				:locale="locale" />
		</section>
	</section>
</template>

<script>
import PublicationDatasetView from './PublicationDatasetView.vue'
import { tablesWithViews } from '../lib/datasetViews.js'

/**
 * The views of a publication's published tables.
 *
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-the-public-page-draws-each-view-and-offers-its-data-as-a-table-req-dsv-003
 */
export default {
	name: 'PublicationDatasetViews',

	components: { PublicationDatasetView },

	props: {
		/** The publication answer, with `tables[].views`. */
		publication: { type: Object, default: null },
		/** The page's translator. */
		t: { type: Function, required: true },
		/** The page's language. */
		locale: { type: String, default: 'nl' },
	},

	computed: {
		/**
		 * @return {Array<object>} The tables that carry views, each with its views in order.
		 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-the-public-page-draws-each-view-and-offers-its-data-as-a-table-req-dsv-003
		 */
		tables() {
			return tablesWithViews(this.publication)
		},
	},
}
</script>
