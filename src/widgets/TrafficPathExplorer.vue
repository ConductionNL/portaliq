<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  TrafficPathExplorer — the steps visitors took from a starting point, or
  to an ending point, over the page's portal, period and segment
  (portal-traffic-path-explorer). It replaces the Journeys table, whose
  page-to-page pairs could not say what one visitor did next.

  The counts come from /api/traffic/paths, which reads the raw events and
  counts each visit's own path. This widget draws them: one column per
  step, a node per page with its visits, a "+N more" node for the rest, a
  red stub for the visits that ended there, and grey bands between steps.
  No chart library: nothing in the stack draws a flow diagram, and the
  geometry is a pure function (src/lib/trafficPaths.js) with its own node
  tests. Every colour is a theme variable, so dark mode and NL Design
  themes apply unchanged.

  A page node is a button: Tab reaches it, Enter or Space chooses it, and
  the next steps then count only the visits that passed it. The same
  numbers are in a table under "Show as a table".

  @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-follow-the-pages-portal-period-and-segment
  @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-be-operable-by-keyboard-and-readable-without-the-diagram
-->
<template>
	<div class="traffic-paths" data-testid="traffic-paths">
		<TrafficEmptyState :state="emptyState" />
		<template v-if="emptyState === ''">
			<div class="traffic-paths__toolbar">
				<NcSelect
					:modelValue="selectedMode"
					:inputLabel="t('portaliq', 'Direction')"
					:options="modeOptions"
					:clearable="false"
					label="label"
					data-testid="traffic-paths-mode"
					@update:modelValue="onMode" />
				<NcSelect
					:modelValue="selectedAnchor"
					:inputLabel="
						mode === 'end'
							? t('portaliq', 'Ending point')
							: t('portaliq', 'Starting point')
					"
					:options="anchorOptions"
					:clearable="false"
					label="label"
					data-testid="traffic-paths-anchor"
					@update:modelValue="onAnchor" />
				<div
					class="traffic-paths__steps"
					role="group"
					:aria-label="t('portaliq', 'Steps')">
					<NcButton
						:disabled="steps <= 1"
						data-testid="traffic-paths-remove-step"
						@click="setSteps(steps - 1)">
						{{ t('portaliq', 'Remove a step') }}
					</NcButton>
					<span
						class="traffic-paths__count"
						data-testid="traffic-paths-step-count">
						{{ n('portaliq', '%n step', '%n steps', steps) }}
					</span>
					<NcButton
						:disabled="steps >= maxSteps"
						data-testid="traffic-paths-add-step"
						@click="setSteps(steps + 1)">
						{{ t('portaliq', 'Add a step') }}
					</NcButton>
				</div>
				<NcButton
					v-if="trail.length > 0"
					variant="tertiary"
					data-testid="traffic-paths-clear"
					@click="trail = []">
					{{ t('portaliq', 'Clear the chosen pages') }}
				</NcButton>
			</div>

			<NcNoteCard
				v-if="error !== ''"
				type="error"
				data-testid="traffic-paths-error">
				{{ t('portaliq', 'The paths could not be loaded.') }}
			</NcNoteCard>

			<NcNoteCard
				v-if="retentionNote !== ''"
				type="info"
				data-testid="traffic-paths-retention">
				{{ retentionNote }}
			</NcNoteCard>

			<NcNoteCard
				v-if="truncatedNote !== ''"
				type="warning"
				data-testid="traffic-paths-truncated">
				{{ truncatedNote }}
			</NcNoteCard>

			<NcLoadingIcon v-if="loading && !result" :size="32" />

			<p
				v-if="result && result.sessions === 0"
				class="traffic-paths__none"
				data-testid="traffic-paths-none">
				{{
					t(
						'portaliq',
						'No visits match this point in these days. Pick another page or a longer period.',
					)
				}}
			</p>

			<div
				v-if="result && result.sessions > 0"
				class="traffic-paths__canvas"
				:class="{ 'traffic-paths__canvas--busy': loading }">
				<svg
					:width="drawing.width + padding * 2"
					:height="drawing.height + padding"
					:viewBox="viewBox"
					role="group"
					:aria-label="diagramLabel"
					data-testid="traffic-paths-diagram">
					<g
						v-for="column in drawing.columns"
						:key="'h' + column.step"
						aria-hidden="true">
						<text class="traffic-paths__heading" :x="column.x" :y="14">
							{{ stepLabel(column.step) }}
						</text>
						<text
							class="traffic-paths__subheading"
							:x="column.x"
							:y="30"
							data-testid="traffic-paths-step-total">
							{{ stepTotal(column.column) }}
						</text>
					</g>
					<path
						v-for="link in drawing.links"
						:key="link.key"
						class="traffic-paths__band"
						:d="link.d"
						aria-hidden="true"
						data-testid="traffic-paths-band">
						<title>{{ bandTitle(link) }}</title>
					</path>
					<template
						v-for="column in drawing.columns"
						:key="'c' + column.step">
						<g
							v-for="box in column.nodes"
							:key="box.key"
							class="traffic-paths__node"
							:class="{
								'traffic-paths__node--more': box.node.more > 0,
								'traffic-paths__node--chosen': box.node.selected,
							}"
							:tabindex="box.node.more > 0 ? undefined : 0"
							:role="box.node.more > 0 ? 'img' : 'button'"
							:aria-pressed="
								box.node.more > 0
									? undefined
									: String(box.node.selected)
							"
							:aria-label="nodeLabel(box)"
							:data-testid="
								box.node.more > 0
									? 'traffic-paths-more'
									: 'traffic-paths-node'
							"
							:data-path="box.node.path"
							:data-step="box.step"
							@click="choose(box)"
							@keydown.enter.prevent="choose(box)"
							@keydown.space.prevent="choose(box)">
							<rect
								class="traffic-paths__focus"
								:x="box.x - 4"
								:y="box.y - 4"
								:width="box.width + 8"
								:height="box.height + 8" />
							<rect
								class="traffic-paths__box"
								:x="box.x"
								:y="box.y"
								:width="box.width"
								:height="box.height" />
							<rect
								v-if="box.node.dropOffs > 0"
								class="traffic-paths__drop"
								:x="box.dropX"
								:y="box.dropY"
								:width="box.dropWidth"
								:height="Math.max(2, box.dropHeight)" />
							<text
								class="traffic-paths__label"
								:x="box.x + 8"
								:y="box.y + 20"
								aria-hidden="true">
								{{ shortLabel(box.node) }}
							</text>
							<text
								class="traffic-paths__label traffic-paths__label--count"
								:x="box.x + box.width - 8"
								:y="box.y + 20"
								text-anchor="end"
								aria-hidden="true">
								{{ box.node.sessions }}
							</text>
							<title>{{ nodeLabel(box) }}</title>
						</g>
					</template>
				</svg>
			</div>

			<details
				v-if="result && result.sessions > 0"
				class="traffic-paths__alternative"
				data-testid="traffic-paths-table-toggle">
				<summary>{{ t('portaliq', 'Show as a table') }}</summary>
				<table
					class="traffic-table__table"
					data-testid="traffic-paths-table">
					<thead>
						<tr>
							<th scope="col">{{ t('portaliq', 'Step') }}</th>
							<th scope="col">{{ t('portaliq', 'Page') }}</th>
							<th scope="col" class="traffic-table__number">
								{{ t('portaliq', 'Visits') }}
							</th>
							<th scope="col" class="traffic-table__number">
								{{
									mode === 'end'
										? t('portaliq', 'Began here')
										: t('portaliq', 'Ended here')
								}}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="row in rows"
							:key="row.step + ' ' + row.path + ' ' + row.more">
							<td>{{ stepLabel(row.step) }}</td>
							<td class="traffic-table__path">
								{{ pageName(row) }}
								<span
									v-if="row.selected"
									class="traffic-table__muted">
									{{ t('portaliq', '(chosen)') }}
								</span>
							</td>
							<td class="traffic-table__number">{{ row.sessions }}</td>
							<td class="traffic-table__number">{{ row.dropOffs }}</td>
						</tr>
					</tbody>
				</table>
			</details>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import TrafficEmptyState from './TrafficEmptyState.vue'
import { chooseNode, layoutPaths, pathRows } from '../lib/trafficPaths.js'
import trafficWidgetMixin from './trafficWidgetMixin.js'

/**
 * The most steps after the start or end point, the endpoint's limit.
 */
const MAX_STEPS = 10

/**
 * The longest label drawn inside a node; the full path is in its title.
 */
const LABEL_CHARACTERS = 18

export default {
	name: 'TrafficPathExplorer',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		TrafficEmptyState,
	},

	mixins: [trafficWidgetMixin],

	data() {
		return {
			mode: 'start',
			anchor: '',
			steps: 3,
			trail: [],
			result: null,
			loading: false,
			error: '',
			request: 0,
			maxSteps: MAX_STEPS,
			padding: 16,
		}
	},

	computed: {
		/**
		 * Everything the answer depends on, as one key to watch.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-follow-the-pages-portal-period-and-segment
		 * @return {string} The key, '' when there is nothing to ask for.
		 */
		query() {
			const dates = this.report.dates
			if (
				this.emptyState !== ''
				|| !this.report.portalSlug
				|| dates.length === 0
			) {
				return ''
			}
			return JSON.stringify({
				portal: this.report.portalSlug,
				from: dates[0],
				to: dates[dates.length - 1],
				segment: this.report.segment,
				mode: this.mode,
				anchor: this.anchor,
				steps: String(this.steps),
				trail: this.trail.length > 0 ? JSON.stringify(this.trail) : '',
			})
		},

		/**
		 * The two directions, for the direction picker.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-start-from-a-visits-start-or-a-page-or-end-at-a-visits-end-or-a-page
		 * @return {Array<{id: string, label: string}>} The options.
		 */
		modeOptions() {
			return [
				{
					id: 'start',
					label: t('portaliq', 'Forward from a starting point'),
				},
				{ id: 'end', label: t('portaliq', 'Backward from an ending point') },
			]
		},

		/**
		 * The chosen direction's option.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-start-from-a-visits-start-or-a-page-or-end-at-a-visits-end-or-a-page
		 * @return {object} The option.
		 */
		selectedMode() {
			return (
				this.modeOptions.find((o) => o.id === this.mode)
				|| this.modeOptions[0]
			)
		},

		/**
		 * The start or end of a visit, then the portal's top pages for the
		 * period, then the chosen page when it is not among them.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-start-from-a-visits-start-or-a-page-or-end-at-a-visits-end-or-a-page
		 * @return {Array<{id: string, label: string}>} The options.
		 */
		anchorOptions() {
			const edge = {
				id: '',
				label:
					this.mode === 'end'
						? t('portaliq', 'End of a visit')
						: t('portaliq', 'Start of a visit'),
			}
			const pages = (this.summary.pages || [])
				.map((p) => p && p.path)
				.filter((p) => typeof p === 'string' && p.startsWith('/'))
			if (this.anchor !== '' && !pages.includes(this.anchor)) {
				pages.unshift(this.anchor)
			}
			return [edge, ...pages.map((p) => ({ id: p, label: p }))]
		},

		/**
		 * The chosen point's option.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-start-from-a-visits-start-or-a-page-or-end-at-a-visits-end-or-a-page
		 * @return {object} The option.
		 */
		selectedAnchor() {
			return (
				this.anchorOptions.find((o) => o.id === this.anchor)
				|| this.anchorOptions[0]
			)
		},

		/**
		 * The drawing's geometry.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-each-step-must-show-its-busiest-pages-the-rest-as-one-node-and-where-visits-ended
		 * @return {object} See `layoutPaths`.
		 */
		drawing() {
			return layoutPaths(this.result)
		},

		/**
		 * The SVG view box, with room for the drop-off stubs on either side.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-each-step-must-show-its-busiest-pages-the-rest-as-one-node-and-where-visits-ended
		 * @return {string} The view box.
		 */
		viewBox() {
			return [
				-this.padding,
				0,
				this.drawing.width + this.padding * 2,
				this.drawing.height + this.padding,
			].join(' ')
		},

		/**
		 * The table's rows.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-be-operable-by-keyboard-and-readable-without-the-diagram
		 * @return {Array<object>} See `pathRows`.
		 */
		rows() {
			return pathRows(this.result)
		},

		/**
		 * What a screen reader hears for the diagram as a whole.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-be-operable-by-keyboard-and-readable-without-the-diagram
		 * @return {string} The label.
		 */
		diagramLabel() {
			return n(
				'portaliq',
				'Paths of %n visit. Each page is a button; choose one to follow its visits.',
				'Paths of %n visits. Each page is a button; choose one to follow its visits.',
				(this.result && this.result.sessions) || 0,
			)
		},

		/**
		 * The note for a period that reaches past the kept raw events.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-say-when-it-shows-less-than-the-chosen-period
		 * @return {string} The note, '' when the period is fully kept.
		 */
		retentionNote() {
			const coverage = this.result && this.result.coverage
			if (!coverage || !coverage.beyondRetention) {
				return ''
			}
			if (!coverage.from) {
				return n(
					'portaliq',
					'Paths are built from visits of the last %n day, and this period is older. Pick a more recent period.',
					'Paths are built from visits of the last %n days, and this period is older. Pick a more recent period.',
					coverage.retentionDays,
				)
			}
			return t(
				'portaliq',
				'Paths are built from visits of the last {days} days only. They cover {from} to {to}, not the whole period.',
				{
					days: coverage.retentionDays,
					from: coverage.from,
					to: coverage.to,
				},
			)
		},

		/**
		 * The note for a read the event cap stopped.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-say-when-it-shows-less-than-the-chosen-period
		 * @return {string} The note, '' when nothing was cut.
		 */
		truncatedNote() {
			const result = this.result
			if (!result || !result.truncated || !result.coverage) {
				return ''
			}
			const covered = t(
				'portaliq',
				'This shows the most recent visits only. The read stopped at {cap} events, so the paths cover {from} to {to}.',
				{
					cap: result.eventCap,
					from: result.coverage.from,
					to: result.coverage.to,
				},
			)
			if (!result.coverage.partialDay) {
				return covered
			}
			return (
				covered
				+ ' '
				+ t('portaliq', 'Visits on {day} are only partly counted.', {
					day: result.coverage.partialDay,
				})
			)
		},
	},

	watch: {
		/**
		 * Ask again whenever the portal, period, segment or a choice changes.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-follow-the-pages-portal-period-and-segment
		 * @return {void}
		 */
		query: {
			/**
			 * Load the paths for the new query.
			 *
			 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-follow-the-pages-portal-period-and-segment
			 * @return {void}
			 */
			handler() {
				this.load()
			},

			immediate: true,
		},

		/**
		 * Another portal starts over: its pages are not this portal's pages.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-reader-must-be-able-to-expand-from-a-node-and-change-the-number-of-steps
		 * @return {void}
		 */
		'report.portalSlug': function () {
			this.anchor = ''
			this.trail = []
		},
	},

	methods: {
		/**
		 * Fetch the paths for the current query. An answer that arrives
		 * after a newer question was asked is dropped.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-follow-the-pages-portal-period-and-segment
		 * @return {Promise<void>}
		 */
		async load() {
			const query = this.query
			if (query === '') {
				this.result = null
				return
			}
			const ticket = ++this.request
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(
					generateUrl('/apps/portaliq/api/traffic/paths'),
					{ params: JSON.parse(query) },
				)
				if (ticket === this.request) {
					this.result = response.data
				}
			} catch (error) {
				if (ticket === this.request) {
					this.result = null
					this.error = String((error && error.message) || error)
				}
			} finally {
				if (ticket === this.request) {
					this.loading = false
				}
			}
		},

		/**
		 * Switch between forward and backward. The point and the choices
		 * belong to one direction, so both start over.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-start-from-a-visits-start-or-a-page-or-end-at-a-visits-end-or-a-page
		 * @param {object|null} option The chosen option.
		 * @return {void}
		 */
		onMode(option) {
			const mode = option && option.id === 'end' ? 'end' : 'start'
			if (mode === this.mode) {
				return
			}
			this.mode = mode
			this.trail = []
		},

		/**
		 * Choose the starting or ending point.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-start-from-a-visits-start-or-a-page-or-end-at-a-visits-end-or-a-page
		 * @param {object|null} option The chosen option.
		 * @return {void}
		 */
		onAnchor(option) {
			this.anchor = (option && option.id) || ''
			this.trail = []
		},

		/**
		 * Show more or fewer steps; a choice on a step that is removed goes.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-reader-must-be-able-to-expand-from-a-node-and-change-the-number-of-steps
		 * @param {number} steps The new number.
		 * @return {void}
		 */
		setSteps(steps) {
			this.steps = Math.max(1, Math.min(MAX_STEPS, steps))
			if (this.trail.length > this.steps + 1) {
				this.trail = this.trail.slice(0, this.steps + 1)
			}
		},

		/**
		 * Choose a page node, or undo the choice. A "+N more" node holds
		 * several pages and cannot be followed.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-reader-must-be-able-to-expand-from-a-node-and-change-the-number-of-steps
		 * @param {object} box The node's drawing.
		 * @return {void}
		 */
		choose(box) {
			if (box.node.more > 0) {
				return
			}
			this.trail = chooseNode(this.trail, box.step, box.node.path)
		},

		/**
		 * A step's heading: "Step 0", "Step +2", or "Step -2" reading back.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-each-step-must-show-its-busiest-pages-the-rest-as-one-node-and-where-visits-ended
		 * @param {number} step The step.
		 * @return {string} The heading.
		 */
		stepLabel(step) {
			if (step === 0) {
				return t('portaliq', 'Step 0')
			}
			return t('portaliq', 'Step {step}', {
				step: (this.mode === 'end' ? '-' : '+') + step,
			})
		},

		/**
		 * A step's totals: its visits, and how many ended (or began) there.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-each-step-must-show-its-busiest-pages-the-rest-as-one-node-and-where-visits-ended
		 * @param {object} column The step's column.
		 * @return {string} The line.
		 */
		stepTotal(column) {
			if (this.mode === 'end') {
				return t('portaliq', '{visits} visits, {count} began here', {
					visits: column.sessions,
					count: column.dropOffs,
				})
			}
			return t('portaliq', '{visits} visits, {count} ended here', {
				visits: column.sessions,
				count: column.dropOffs,
			})
		},

		/**
		 * A node's page, or "+N more" for the rest of a step.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-each-step-must-show-its-busiest-pages-the-rest-as-one-node-and-where-visits-ended
		 * @param {object} node The node, or a table row.
		 * @return {string} The name.
		 */
		pageName(node) {
			if (node.more > 0) {
				return n('portaliq', '+%n more page', '+%n more pages', node.more)
			}
			return node.path
		},

		/**
		 * The name cut to what fits inside a node.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-each-step-must-show-its-busiest-pages-the-rest-as-one-node-and-where-visits-ended
		 * @param {object} node The node.
		 * @return {string} The label.
		 */
		shortLabel(node) {
			const name = this.pageName(node)
			if (name.length <= LABEL_CHARACTERS) {
				return name
			}
			return name.substring(0, LABEL_CHARACTERS - 1) + '…'
		},

		/**
		 * What a screen reader hears for a node, and the tooltip.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-be-operable-by-keyboard-and-readable-without-the-diagram
		 * @param {object} box The node's drawing.
		 * @return {string} The label.
		 */
		nodeLabel(box) {
			const values = {
				step: this.stepLabel(box.step),
				page: this.pageName(box.node),
				visits: box.node.sessions,
				count: box.node.dropOffs,
			}
			if (this.mode === 'end') {
				return t(
					'portaliq',
					'{step}, {page}: {visits} visits, {count} began here',
					values,
				)
			}
			return t(
				'portaliq',
				'{step}, {page}: {visits} visits, {count} ended here',
				values,
			)
		},

		/**
		 * The tooltip of a band.
		 *
		 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-each-step-must-show-its-busiest-pages-the-rest-as-one-node-and-where-visits-ended
		 * @param {object} link The band's drawing.
		 * @return {string} The text.
		 */
		bandTitle(link) {
			const from = this.result.columns[link.step].nodes[link.source]
			const to = this.result.columns[link.step + 1].nodes[link.target]
			return t('portaliq', '{from} to {to}: {visits} visits', {
				from: this.pageName(from),
				to: this.pageName(to),
				visits: link.sessions,
			})
		},
	},
}
</script>

<style scoped src="./trafficTable.css"></style>

<style scoped>
.traffic-paths {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 4px;
	height: 100%;
	overflow: auto;
}

.traffic-paths__toolbar {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px 16px;
}

.traffic-paths__steps {
	display: flex;
	align-items: center;
	gap: 8px;
}

.traffic-paths__count {
	min-width: 7ch;
	text-align: center;
	font-variant-numeric: tabular-nums;
}

.traffic-paths__none {
	padding: 8px 4px;
	color: var(--color-text-maxcontrast);
}

.traffic-paths__canvas {
	overflow-x: auto;
	padding-block: 4px;
}

.traffic-paths__canvas--busy {
	opacity: 0.6;
}

.traffic-paths__canvas svg {
	display: block;
	font-size: 13px;
}

.traffic-paths__heading {
	fill: var(--color-main-text);
	font-weight: bold;
}

.traffic-paths__subheading {
	fill: var(--color-text-maxcontrast);
	font-size: 12px;
}

.traffic-paths__band {
	fill: var(--color-border-dark);
	opacity: 0.55;
}

.traffic-paths__band:hover {
	opacity: 0.85;
}

.traffic-paths__node {
	cursor: pointer;
	outline: none;
}

.traffic-paths__node--more {
	cursor: default;
}

.traffic-paths__box {
	fill: var(--color-primary-element);
	rx: 3px;
}

.traffic-paths__node:hover .traffic-paths__box {
	fill: var(--color-primary-element-hover);
}

.traffic-paths__node--more .traffic-paths__box,
.traffic-paths__node--more:hover .traffic-paths__box {
	fill: var(--color-background-darker);
}

.traffic-paths__node--chosen .traffic-paths__box {
	stroke: var(--color-main-text);
	stroke-width: 3px;
}

.traffic-paths__focus {
	fill: none;
	stroke: transparent;
	stroke-width: 2px;
	rx: 5px;
}

.traffic-paths__node:focus-visible .traffic-paths__focus {
	stroke: var(--color-main-text);
	stroke-dasharray: 4 2;
}

.traffic-paths__drop {
	fill: var(--color-error);
}

.traffic-paths__label {
	fill: var(--color-primary-element-text);
	pointer-events: none;
}

.traffic-paths__label--count {
	font-variant-numeric: tabular-nums;
}

.traffic-paths__node--more .traffic-paths__label {
	fill: var(--color-main-text);
}

.traffic-paths__alternative summary {
	cursor: pointer;
	padding: 4px;
	color: var(--color-main-text);
}
</style>
