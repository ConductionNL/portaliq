<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  One dataset view on the publication page (dataset-views-on-the-public-page,
  REQ-DSV-003): a paged table, a bar or line chart drawn as SVG in the NL
  Design System tokens, or the portal's location map. Every chart and map
  offers "Show as table" with the same data, so a reader who cannot see the
  drawing gets the same facts.
-->
<template>
	<figure
		class="pq-dataset-view"
		:data-kind="view.kind"
		data-testid="publication-dataset-view">
		<figcaption :id="captionId" class="pq-dataset-view__title">
			{{ view.title || t('Data') }}
		</figcaption>

		<p v-if="loading" class="pq-dataset-view__status" role="status">
			{{ t('Loading…') }}
		</p>
		<p v-else-if="error" class="pq-dataset-view__status" role="status">
			{{ t('This view could not be loaded.') }}
		</p>

		<template v-else>
			<button
				v-if="view.kind !== 'table'"
				type="button"
				class="utrecht-button utrecht-button--secondary-action pq-dataset-view__toggle"
				:aria-pressed="asTable ? 'true' : 'false'"
				data-testid="publication-dataset-view-toggle"
				@click="asTable = !asTable">
				{{ asTable ? t('Show as chart') : t('Show as table') }}
			</button>

			<svg
				v-if="!asTable && view.kind === 'bar'"
				class="pq-dataset-view__chart"
				:viewBox="`0 0 ${box.width} ${box.height}`"
				role="img"
				:aria-labelledby="captionId"
				data-testid="publication-dataset-view-chart">
				<line
					class="pq-dataset-view__axis"
					:x1="box.left"
					:x2="box.width - box.right"
					:y1="bars.baseline"
					:y2="bars.baseline" />
				<text
					class="pq-dataset-view__tick"
					:x="box.left - 6"
					:y="box.top + 4"
					text-anchor="end">{{ cellText(bars.max) }}</text>
				<g v-for="(bar, index) in bars.bars" :key="index">
					<rect
						class="pq-dataset-view__bar"
						:x="bar.x"
						:y="bar.y"
						:width="bar.width"
						:height="bar.height">
						<title>{{ `${bar.label}: ${cellText(bar.value)}` }}</title>
					</rect>
					<text
						v-if="bars.bars.length <= 24"
						class="pq-dataset-view__tick"
						:x="bar.x + bar.width / 2"
						:y="box.height - box.bottom + 16"
						text-anchor="middle">{{ shortLabel(bar.label) }}</text>
				</g>
			</svg>

			<svg
				v-else-if="!asTable && view.kind === 'line'"
				class="pq-dataset-view__chart"
				:viewBox="`0 0 ${box.width} ${box.height}`"
				role="img"
				:aria-labelledby="captionId"
				data-testid="publication-dataset-view-chart">
				<line
					class="pq-dataset-view__axis"
					:x1="box.left"
					:x2="box.width - box.right"
					:y1="line.baseline"
					:y2="line.baseline" />
				<text
					class="pq-dataset-view__tick"
					:x="box.left - 6"
					:y="box.top + 4"
					text-anchor="end">{{ cellText(line.max) }}</text>
				<path class="pq-dataset-view__line" :d="line.path" />
				<g v-for="(dot, index) in line.dots" :key="index">
					<circle
						class="pq-dataset-view__dot"
						:cx="dot.x"
						:cy="dot.y"
						r="4">
						<title>{{ `${dot.label}: ${cellText(dot.value)}` }}</title>
					</circle>
					<text
						v-if="line.dots.length <= 24"
						class="pq-dataset-view__tick"
						:x="dot.x"
						:y="box.height - box.bottom + 16"
						text-anchor="middle">{{ shortLabel(dot.label) }}</text>
				</g>
			</svg>

			<NlLocationMap
				v-else-if="!asTable && view.kind === 'map'"
				:heading="t('{count} points')"
				:locations="places"
				:allLabel="t('All points')"
				:filterLabel="t('Kind of point')"
				:mapLabel="t('Map of the numbered points in the list')"
				:zoomInLabel="t('Zoom in')"
				:zoomOutLabel="t('Zoom out')"
				:routeLabel="t('Route')"
				:newWindowLabel="t('opens in a new window')"
				:routeNote="t('Route opens a route planner in a new window.')" />

			<table
				v-if="asTable || view.kind === 'table'"
				class="utrecht-table pq-dataset-view__table"
				:aria-labelledby="captionId"
				data-testid="publication-dataset-view-table">
				<thead class="utrecht-table__header">
					<tr class="utrecht-table__row">
						<th
							v-for="(column, index) in table.columns"
							:key="index"
							scope="col"
							class="utrecht-table__header-cell">
							{{ column }}
						</th>
					</tr>
				</thead>
				<tbody class="utrecht-table__body">
					<tr
						v-for="(row, rowIndex) in table.rows"
						:key="rowIndex"
						class="utrecht-table__row">
						<td
							v-for="(cell, cellIndex) in row"
							:key="cellIndex"
							class="utrecht-table__cell">
							{{ cell }}
						</td>
					</tr>
				</tbody>
			</table>

			<p v-if="table.rows.length === 0" class="pq-dataset-view__status">
				{{ t('This view has no data yet.') }}
			</p>
			<p v-if="data && data.truncated" class="pq-dataset-view__note">
				{{ t('Only the first part of the data is shown.') }}
			</p>
			<p v-if="view.kind === 'map' && data && data.skipped > 0" class="pq-dataset-view__note">
				{{ t('{count} rows without a location are not on the map.', { count: data.skipped }) }}
			</p>

			<nav
				v-if="view.kind === 'table' && pages > 1"
				class="pq-dataset-view__pages"
				:aria-label="t('Pages of {title}', { title: view.title || t('Data') })">
				<button
					type="button"
					class="utrecht-button utrecht-button--subtle"
					:disabled="page <= 1 || !rowsUrl"
					data-testid="publication-dataset-view-previous"
					@click="goTo(page - 1)">
					{{ t('Previous') }}
				</button>
				<span aria-live="polite">{{ t('Page {page} of {pages}', { page, pages }) }}</span>
				<button
					type="button"
					class="utrecht-button utrecht-button--subtle"
					:disabled="page >= pages || !rowsUrl"
					data-testid="publication-dataset-view-next"
					@click="goTo(page + 1)">
					{{ t('Next') }}
				</button>
			</nav>
		</template>
	</figure>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import {
	barGeometry,
	cellText,
	CHART_BOX,
	chartPoints,
	lineGeometry,
	mapPlaces,
	pagesOf,
	pageUrl,
	viewTable,
} from '../lib/datasetViews.js'

let instances = 0

/**
 * One view of a published table, read from its public data address.
 *
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-the-public-page-draws-each-view-and-offers-its-data-as-a-table-req-dsv-003
 */
export default {
	name: 'PublicationDatasetView',

	components: {
		NlLocationMap: defineAsyncComponent(() => import('../widgets/nlLocationMap/NlLocationMap.vue')),
	},

	props: {
		/** The view, as the publication answer lists it (with `dataUrl`). */
		view: { type: Object, required: true },
		/** The table's rows endpoint, for the pages after the first. */
		rowsUrl: { type: String, default: '' },
		/** The page's translator. */
		t: { type: Function, required: true },
		/** The page's language. */
		locale: { type: String, default: 'nl' },
	},

	/**
	 * @return {object} The state.
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-the-public-page-draws-each-view-and-offers-its-data-as-a-table-req-dsv-003
	 */
	data() {
		instances += 1
		return {
			captionId: `pq-dataset-view-${instances}`,
			data: null,
			loading: true,
			error: false,
			asTable: false,
			page: 1,
			box: CHART_BOX,
		}
	},

	computed: {
		/**
		 * @return {{columns: Array<string>, rows: Array<Array<string>>}} The view as a table.
		 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-the-public-page-draws-each-view-and-offers-its-data-as-a-table-req-dsv-003
		 */
		table() {
			return viewTable(this.view, this.data, { t: this.t, locale: this.locale })
		},

		/**
		 * @return {object} The bars of a bar view.
		 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-the-public-page-draws-each-view-and-offers-its-data-as-a-table-req-dsv-003
		 */
		bars() {
			return barGeometry(chartPoints(this.data), this.box)
		},

		/**
		 * @return {object} The line of a line view.
		 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-the-public-page-draws-each-view-and-offers-its-data-as-a-table-req-dsv-003
		 */
		line() {
			return lineGeometry(chartPoints(this.data), this.box)
		},

		/**
		 * @return {Array<object>} The points of a map view, for the location map.
		 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-the-public-page-draws-each-view-and-offers-its-data-as-a-table-req-dsv-003
		 */
		places() {
			return mapPlaces(this.data, this.t)
		},

		/**
		 * @return {number} The pages of a table view.
		 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-the-public-page-draws-each-view-and-offers-its-data-as-a-table-req-dsv-003
		 */
		pages() {
			return pagesOf(this.data)
		},
	},

	mounted() {
		this.load(this.view.dataUrl)
	},

	methods: {
		/**
		 * Read one answer: the view's data, or a later page of its table.
		 *
		 * @param {string} address The address to read.
		 * @return {Promise<void>} Resolves once the state is updated.
		 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-the-public-page-draws-each-view-and-offers-its-data-as-a-table-req-dsv-003
		 */
		async load(address) {
			this.loading = true
			this.error = false
			try {
				const response = await fetch(new URL(address, window.location.origin).toString(), {
					headers: { Accept: 'application/json' },
				})
				if (response.ok === false) {
					throw new Error(`HTTP ${response.status}`)
				}
				const body = await response.json()
				// A later page comes from the rows endpoint, which answers every
				// column: keep the view's own columns.
				this.data = this.view.kind === 'table' && this.data
					? { ...body, columns: this.data.columns }
					: body
			} catch {
				// No reason surfaced at a public origin.
				this.error = true
			} finally {
				this.loading = false
			}
		},

		/**
		 * Move a table view to another page.
		 *
		 * @param {number} page The page, from 1.
		 * @return {Promise<void>} Resolves once loaded.
		 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-the-public-page-draws-each-view-and-offers-its-data-as-a-table-req-dsv-003
		 */
		async goTo(page) {
			const address = page === 1 ? this.view.dataUrl : pageUrl(this.rowsUrl, page, window.location.origin)
			if (!address) {
				return
			}
			this.page = page
			await this.load(address)
		},

		/**
		 * @param {unknown} value A value.
		 * @return {string} The value in the page's language.
		 */
		cellText(value) {
			return cellText(value, this.locale)
		},

		/**
		 * @param {string} label A group label.
		 * @return {string} The label, cut to fit under its bar.
		 */
		shortLabel(label) {
			const text = String(label)
			return text.length > 12 ? `${text.slice(0, 11)}…` : text
		},
	},
}
</script>

<style scoped>
.pq-dataset-view {
	margin: 0 0 24px;
}

.pq-dataset-view__title {
	font-weight: bold;
	margin-block-end: 8px;
}

.pq-dataset-view__toggle {
	margin-block-end: 8px;
}

.pq-dataset-view__chart {
	display: block;
	inline-size: 100%;
	max-inline-size: 640px;
	block-size: auto;
}

.pq-dataset-view__bar {
	fill: var(--nldesign-color-primary, var(--utrecht-color-blue-40));
}

.pq-dataset-view__line {
	fill: none;
	stroke: var(--nldesign-color-primary, var(--utrecht-color-blue-40));
	stroke-width: 2;
}

.pq-dataset-view__dot {
	fill: var(--nldesign-color-primary, var(--utrecht-color-blue-40));
}

.pq-dataset-view__axis {
	stroke: var(--utrecht-color-grey-20);
}

.pq-dataset-view__tick {
	fill: var(--utrecht-document-color, currentColor);
	font-size: 12px;
}

.pq-dataset-view__table {
	inline-size: 100%;
}

.pq-dataset-view__note,
.pq-dataset-view__status {
	color: var(--utrecht-document-color, inherit);
	font-size: 0.9em;
}

.pq-dataset-view__pages {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-block-start: 8px;
}
</style>
