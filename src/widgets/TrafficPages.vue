<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  TrafficPages — the ten most viewed pages of the chosen range, with how
  often each was where a visit started and where it ended
  (portal-traffic-analytics), and the paths that were requested but do
  not exist (portal-traffic-outcomes).

  @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-session-must-be-reconstructable-into-an-ordered-journey
  @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-missing-pages-must-be-reported-by-the-renderer-and-listed
-->
<template>
	<div class="traffic-table" data-testid="traffic-pages">
		<TrafficEmptyState :state="emptyState" />
		<table
			v-if="emptyState === '' && showPages"
			class="traffic-table__table"
			data-testid="traffic-pages-table">
			<thead>
				<tr>
					<th scope="col">{{ t('portaliq', 'Page') }}</th>
					<th scope="col" class="traffic-table__number">
						{{ t('portaliq', 'Views') }}
					</th>
					<th scope="col" class="traffic-table__number">
						{{ t('portaliq', 'Entrances') }}
					</th>
					<th scope="col" class="traffic-table__number">
						{{ t('portaliq', 'Exits') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in summary.pages" :key="row.path">
					<td class="traffic-table__path">{{ row.path }}</td>
					<td class="traffic-table__number">{{ row.views }}</td>
					<td class="traffic-table__number">{{ row.entrances }}</td>
					<td class="traffic-table__number">{{ row.exits }}</td>
				</tr>
			</tbody>
		</table>

		<!-- Missing pages (portal-traffic-outcomes): the paths the renderer
		     answered with not found. Under the pages by default; with
		     `content.part: "missing"` they are a card of their own. -->
		<h3
			v-if="emptyState === '' && part === 'all' && summary.notFound.length > 0"
			class="traffic-table__subheading">
			{{ t('portaliq', 'Missing pages') }}
		</h3>
		<table
			v-if="emptyState === '' && showMissing"
			class="traffic-table__table"
			data-testid="traffic-missing-pages">
			<thead>
				<tr>
					<th scope="col">{{ t('portaliq', 'Requested path') }}</th>
					<th scope="col" class="traffic-table__number">
						{{ t('portaliq', 'Times') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in summary.notFound" :key="row.path">
					<td class="traffic-table__path">{{ row.path }}</td>
					<td class="traffic-table__number">{{ row.hits }}</td>
				</tr>
				<tr v-if="summary.notFound.length === 0">
					<td colspan="2" class="traffic-table__muted">
						{{ t('portaliq', 'No missing page requested in this period.') }}
					</td>
				</tr>
			</tbody>
		</table>
		<p
			v-if="emptyState === '' && part === 'missing'"
			class="traffic-table__muted traffic-table__note"
			data-testid="traffic-missing-pages-note">
			{{
				t(
					'portaliq',
					'Addresses where visitors looked for a page that does not exist. Make the page or a redirect.',
				)
			}}
		</p>
	</div>
</template>

<script>
import TrafficEmptyState from './TrafficEmptyState.vue'
import trafficWidgetMixin from './trafficWidgetMixin.js'

export default {
	name: 'TrafficPages',

	components: {
		TrafficEmptyState,
	},

	mixins: [trafficWidgetMixin],

	props: {
		/**
		 * The widget's manifest content. `part` picks what it shows: "all"
		 * (the default: pages, then the missing pages under them), "pages"
		 * or "missing".
		 */
		content: {
			type: Object,
			default: () => ({}),
		},
	},

	computed: {
		/**
		 * Which part of the pages report this card shows.
		 *
		 * @return {string} "all", "pages" or "missing".
		 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-missing-pages-must-be-reported-by-the-renderer-and-listed
		 */
		part() {
			return ['pages', 'missing'].includes(this.content?.part) ? this.content.part : 'all'
		},

		/**
		 * Whether the most viewed pages are shown.
		 *
		 * @return {boolean} True unless the card shows only the missing pages.
		 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-session-must-be-reconstructable-into-an-ordered-journey
		 */
		showPages() {
			return this.part !== 'missing'
		},

		/**
		 * Whether the missing pages are shown: always on their own card,
		 * under the pages only when there are any.
		 *
		 * @return {boolean} True when the missing pages table is drawn.
		 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-missing-pages-must-be-reported-by-the-renderer-and-listed
		 */
		showMissing() {
			if (this.part === 'missing') {
				return true
			}
			return this.part === 'all' && this.summary.notFound.length > 0
		},
	},
}
</script>

<style scoped src="./trafficTable.css"></style>
