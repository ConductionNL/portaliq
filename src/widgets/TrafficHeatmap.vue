<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  TrafficHeatmap — where visitors clicked and how far they scrolled on
  one page over the chosen range (portal-traffic-experiments): the click
  grid drawn on a plain proportional rectangle, the scroll depth as a
  bar per decile.

  NOT A SCREENSHOT, ON PURPOSE. The page is public; the reader can open
  it beside this map. Storing a rendering of the page next to the clicks
  would double what the app keeps for no gain, and the map's whole point
  is that it holds positions and nothing else. The widget says so.

  @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-heatmaps-must-be-off-by-default-and-hold-positions-never-content
-->
<template>
	<div class="traffic-heatmap" data-testid="traffic-heatmap">
		<TrafficEmptyState :state="emptyState" />

		<p
			v-if="emptyState === '' && !heatmapsOn"
			class="traffic-table__muted"
			data-testid="traffic-heatmap-off">
			{{
				t(
					'portaliq',
					'Heatmaps are off for this portal. Switch them on under sensitive measurement in the portal settings; the switch carries its own warning.',
				)
			}}
		</p>

		<p
			v-else-if="emptyState === '' && pages.length === 0"
			class="traffic-table__muted"
			data-testid="traffic-heatmap-empty">
			{{ t('portaliq', 'No clicks or scrolls recorded in this period.') }}
		</p>

		<div v-else-if="emptyState === ''" class="traffic-heatmap__body">
			<NcSelect
				:modelValue="selectedPage"
				:inputLabel="t('portaliq', 'Page')"
				:options="pageOptions"
				:clearable="false"
				label="label"
				data-testid="traffic-heatmap-page"
				@update:modelValue="onPage" />

			<p class="traffic-table__muted">
				{{
					t(
						'portaliq',
						'This is not a screenshot: the map holds positions only. The page is public; open it beside this map to see what is under each cell.',
					)
				}}
			</p>

			<div class="traffic-heatmap__panels">
				<figure class="traffic-heatmap__figure">
					<canvas
						ref="canvas"
						class="traffic-heatmap__canvas"
						:width="width"
						:height="height"
						role="img"
						:aria-label="canvasLabel"
						data-testid="traffic-heatmap-canvas" />
					<figcaption class="traffic-table__muted">
						{{ n('portaliq', '%n click', '%n clicks', clickCount) }}
					</figcaption>
				</figure>

				<div
					class="traffic-heatmap__scroll"
					data-testid="traffic-heatmap-scroll">
					<h3 class="traffic-table__subheading">
						{{ t('portaliq', 'Scroll depth') }}
					</h3>
					<ol class="traffic-heatmap__deciles">
						<li
							v-for="(count, index) in scroll"
							:key="index"
							class="traffic-heatmap__decile">
							<span class="traffic-heatmap__decile-label"
								>{{ (index + 1) * 10 }}%</span
							>
							<span
								class="traffic-heatmap__bar"
								role="img"
								:aria-label="decileLabel(index, count)">
								<span
									class="traffic-heatmap__fill"
									:style="{ width: barWidth(count) }" />
							</span>
							<span class="traffic-heatmap__count">{{ count }}</span>
						</li>
					</ol>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import TrafficEmptyState from './TrafficEmptyState.vue'
import trafficWidgetMixin from './trafficWidgetMixin.js'

/**
 * Cells per side of the grid, the same as the aggregation's.
 */
const GRID = 50

export default {
	name: 'TrafficHeatmap',

	components: {
		NcSelect,
		TrafficEmptyState,
	},

	mixins: [trafficWidgetMixin],

	data() {
		return {
			path: '',
			width: 500,
			height: 400,
		}
	},

	computed: {
		/**
		 * Whether the portal switched heatmaps on.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-heatmaps-must-be-off-by-default-and-hold-positions-never-content
		 * @return {boolean} True when on.
		 */
		heatmapsOn() {
			const sensitive =
				this.portal && this.portal.traffic && this.portal.traffic.sensitive
			return Boolean(sensitive && sensitive.heatmaps === true)
		},

		/**
		 * The pages with a heatmap in the range, busiest first.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-heatmaps-must-be-off-by-default-and-hold-positions-never-content
		 * @return {Array<object>} The rows.
		 */
		pages() {
			return this.summary.heatmaps || []
		},

		/**
		 * The pages as select options.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-heatmaps-must-be-off-by-default-and-hold-positions-never-content
		 * @return {Array<{id: string, label: string}>} The options.
		 */
		pageOptions() {
			return this.pages.map((page) => ({
				id: page.path,
				label: this.t('portaliq', '{path} ({samples} samples)', {
					path: page.path,
					samples: page.samples,
				}),
			}))
		},

		/**
		 * The chosen page's row, defaulting to the busiest.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-heatmaps-must-be-off-by-default-and-hold-positions-never-content
		 * @return {object|null} The row.
		 */
		page() {
			return (
				this.pages.find((p) => p.path === this.path) || this.pages[0] || null
			)
		},

		/**
		 * The selected option.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-heatmaps-must-be-off-by-default-and-hold-positions-never-content
		 * @return {object|null} The option.
		 */
		selectedPage() {
			const page = this.page
			return page
				? this.pageOptions.find((o) => o.id === page.path) || null
				: null
		},

		/**
		 * The chosen page's scroll deciles.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-heatmaps-must-be-off-by-default-and-hold-positions-never-content
		 * @return {Array<number>} Ten counts.
		 */
		scroll() {
			const page = this.page
			const deciles = page && Array.isArray(page.scroll) ? page.scroll : []
			return new Array(10).fill(0).map((_, i) => Number(deciles[i]) || 0)
		},

		/**
		 * The chosen page's clicks, summed.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-heatmaps-must-be-off-by-default-and-hold-positions-never-content
		 * @return {number} The clicks.
		 */
		clickCount() {
			const page = this.page
			return page && Array.isArray(page.clicks)
				? page.clicks.reduce((sum, c) => sum + (Number(c.count) || 0), 0)
				: 0
		},

		/**
		 * What a screen reader gets instead of the canvas.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-heatmaps-must-be-off-by-default-and-hold-positions-never-content
		 * @return {string} The label.
		 */
		canvasLabel() {
			const page = this.page
			return this.t(
				'portaliq',
				'Click map of {path}: {clicks} clicks on a 50 by 50 grid, darker where more visitors clicked.',
				{ path: page ? page.path : '', clicks: this.clickCount },
			)
		},
	},

	watch: {
		page: 'draw',
	},

	mounted() {
		this.draw()
	},

	methods: {
		/**
		 * Choose a page.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-heatmaps-must-be-off-by-default-and-hold-positions-never-content
		 * @param {object|null} option The chosen option.
		 * @return {void}
		 */
		onPage(option) {
			if (option && option.id) {
				this.path = option.id
			}
		},

		/**
		 * Draw the grid: the page as a rectangle, each clicked cell shaded
		 * by its share of the busiest cell.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-heatmaps-must-be-off-by-default-and-hold-positions-never-content
		 * @return {void}
		 */
		draw() {
			const canvas = this.$refs.canvas
			if (!canvas || typeof canvas.getContext !== 'function') {
				return
			}
			const context = canvas.getContext('2d')
			if (!context) {
				return
			}
			const cellW = this.width / GRID
			const cellH = this.height / GRID
			context.clearRect(0, 0, this.width, this.height)
			context.fillStyle = 'rgba(127, 127, 127, 0.12)'
			context.fillRect(0, 0, this.width, this.height)
			const clicks = (this.page && this.page.clicks) || []
			const max = clicks.reduce((m, c) => Math.max(m, Number(c.count) || 0), 0)
			clicks.forEach((cell) => {
				const share = max > 0 ? (Number(cell.count) || 0) / max : 0
				context.fillStyle =
					'rgba(220, 60, 40, ' + (0.25 + 0.75 * share).toFixed(3) + ')'
				context.fillRect(
					Math.round(Number(cell.x) * cellW),
					Math.round(Number(cell.y) * cellH),
					Math.ceil(cellW),
					Math.ceil(cellH),
				)
			})
			context.strokeStyle = 'rgba(127, 127, 127, 0.6)'
			context.strokeRect(0.5, 0.5, this.width - 1, this.height - 1)
		},

		/**
		 * A decile's bar width as a share of the busiest decile.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-heatmaps-must-be-off-by-default-and-hold-positions-never-content
		 * @param {number} count The decile's count.
		 * @return {string} A CSS width.
		 */
		barWidth(count) {
			const max = Math.max(...this.scroll, 0)
			return max > 0 ? Math.round((count / max) * 100) + '%' : '0%'
		},

		/**
		 * A decile's label for a screen reader.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-heatmaps-must-be-off-by-default-and-hold-positions-never-content
		 * @param {number} index The decile, 0 to 9.
		 * @param {number} count The count.
		 * @return {string} The label.
		 */
		decileLabel(index, count) {
			return this.t(
				'portaliq',
				'{count} visits scrolled to at most {percent}% of the page',
				{
					count,
					percent: (index + 1) * 10,
				},
			)
		},
	},
}
</script>

<style scoped src="./trafficTable.css"></style>

<style scoped>
.traffic-heatmap {
	padding: 4px;
	overflow: auto;
}

.traffic-heatmap__body {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.traffic-heatmap__panels {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	align-items: flex-start;
}

.traffic-heatmap__figure {
	margin: 0;
}

.traffic-heatmap__canvas {
	max-width: 100%;
	height: auto;
	border-radius: var(--border-radius);
}

.traffic-heatmap__scroll {
	flex: 1;
	min-width: 220px;
}

.traffic-heatmap__deciles {
	list-style: none;
	margin: 0;
	padding: 0;
}

.traffic-heatmap__decile {
	display: grid;
	grid-template-columns: 48px 1fr 40px;
	gap: 8px;
	align-items: center;
	padding: 2px 0;
}

.traffic-heatmap__bar {
	display: block;
	height: 12px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	overflow: hidden;
}

.traffic-heatmap__fill {
	display: block;
	height: 100%;
	background: var(--color-primary-element);
}

.traffic-heatmap__count {
	text-align: end;
	font-variant-numeric: tabular-nums;
}
</style>
