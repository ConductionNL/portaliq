<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A table filled from one kind of an app's public index
	(editor-blocks-read-public-app-data): the exam timetable of a school, for
	instance. The editor chooses the app, the kind, the filters and the
	columns; the rows come from the app. A read that fails shows the empty
	sentence, never a half table, and a signed-in visitor's own value for the
	filter `visitor` is resolved by the server, not here.
-->
<template>
	<div class="nl-public-table" data-testid="nl-public-table">
		<NlTable
			v-if="table.rows.length > 0"
			:caption="caption"
			:columns="table.columns"
			:rows="table.rows"
			:display="display" />
		<p
			v-else-if="loaded"
			class="utrecht-paragraph"
			data-testid="nl-public-table-empty">
			{{ emptyLabel }}
		</p>
	</div>
</template>

<script>
import NlTable from '../nlTable/NlTable.vue'
import { fetchCatalogue } from '../../lib/publicCatalogue.js'
import { sourceQuery, tableOf } from '../nlCatalogue/catalogue.js'

import '@utrecht/paragraph-css/dist/index.css'

/**
 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-3
 */
export default {
	name: 'NlPublicTable',

	components: { NlTable },

	props: {
		/** What the table shows, as its caption. */
		caption: { type: String, default: '' },
		/** The source: `{app, kind, filters{}}`. */
		source: { type: Object, default: null },
		/** The columns the app declares for the kind: `[{key, label}]`. */
		columns: { type: Array, default: () => [] },
		/** `plain` or `boxed`. */
		display: { type: String, default: 'plain' },
		/** The sentence when no row matches. */
		emptyLabel: { type: String, default: 'Er is niets om te tonen.' },
		/** The portal slug the page belongs to. */
		portal: { type: String, default: '' },
	},

	data() {
		return { items: [], loaded: false }
	},

	computed: {
		/**
		 * @return {{columns: Array<string>, rows: Array<Array<string>>}} The table.
		 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-3
		 */
		table() {
			return tableOf(this.items, this.columns)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the kind from the app's public index; a failed read leaves no rows.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-3
		 */
		async load() {
			const query = sourceQuery(this.source)
			if (query === null) {
				this.loaded = true
				return
			}
			try {
				const page = await fetchCatalogue(this.portal, {
					...query,
					limit: 50,
				})
				this.items = page.items
			} catch {
				this.items = []
			}
			this.loaded = true
		},
	},
}
</script>
