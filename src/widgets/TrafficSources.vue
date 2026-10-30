<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  TrafficSources — where sessions came from, grouped by channel (organic
  search, referral, social, email, direct), with the busiest referring
  hosts per channel, over the last 30 days (portal-traffic-analytics).

  Below it, the searched terms (portal-traffic-outcomes).

  @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-derived-dimensions-must-be-computed-on-the-server-and-never-accepted-from-the-client
  @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-site-search-must-be-reported-by-the-built-in-search-box
-->
<template>
	<div class="traffic-table" data-testid="traffic-sources">
		<TrafficEmptyState :state="emptyState" />
		<table
			v-if="emptyState === '' && part !== 'searches'"
			class="traffic-table__table"
			data-testid="traffic-sources-table">
			<thead>
				<tr>
					<th scope="col">{{ t('portaliq', 'Channel') }}</th>
					<th scope="col">{{ t('portaliq', 'Referring sites') }}</th>
					<th scope="col" class="traffic-table__number">
						{{ t('portaliq', 'Sessions') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in summary.sources" :key="row.channel">
					<td>{{ row.channel }}</td>
					<td class="traffic-table__path">
						{{ row.hosts.join(', ') || '' }}
					</td>
					<td class="traffic-table__number">{{ row.count }}</td>
				</tr>
				<tr v-if="summary.sources.length === 0">
					<td colspan="3" class="traffic-table__muted">
						{{ t('portaliq', 'No referrer recorded yet.') }}
					</td>
				</tr>
			</tbody>
		</table>

		<!-- Searches (portal-traffic-outcomes): what visitors typed into the
		     site's own search, from the URL and from the built-in search
		     block alike. Only present when the portal keeps search terms. -->
		<h3
			v-if="emptyState === '' && part === 'all' && summary.searches.length > 0"
			class="traffic-table__subheading">
			{{ t('portaliq', 'Searches') }}
		</h3>
		<table
			v-if="emptyState === '' && showSearches"
			class="traffic-table__table"
			data-testid="traffic-searches">
			<thead>
				<tr>
					<th scope="col">{{ t('portaliq', 'Search term') }}</th>
					<th scope="col" class="traffic-table__number">
						{{ t('portaliq', 'Times') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in summary.searches" :key="row.term">
					<td class="traffic-table__path">{{ row.term }}</td>
					<td class="traffic-table__number">{{ row.count }}</td>
				</tr>
				<tr v-if="summary.searches.length === 0">
					<td colspan="2" class="traffic-table__muted">
						{{ t('portaliq', 'No search recorded in this period.') }}
					</td>
				</tr>
			</tbody>
		</table>

		<!-- Searches that found nothing (portal-traffic-zero-result-searches):
		     each term opens the public search, so the editor sees what the
		     visitor saw. -->
		<h3
			v-if="emptyState === '' && part !== 'channels' && showZeroResults"
			class="traffic-table__subheading">
			{{ t('portaliq', 'Searched, nothing found') }}
		</h3>
		<table
			v-if="emptyState === '' && part !== 'channels' && showZeroResults"
			class="traffic-table__table"
			data-testid="traffic-zero-results">
			<thead>
				<tr>
					<th scope="col">{{ t('portaliq', 'Search term') }}</th>
					<th scope="col" class="traffic-table__number">
						{{ t('portaliq', 'Times') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in summary.zeroResultSearches" :key="row.term">
					<td class="traffic-table__path">
						<a
							v-if="searchLink(row.term) !== ''"
							:href="searchLink(row.term)"
							target="_blank"
							rel="noopener"
							>{{ row.term }}</a
						>
						<template v-else>
							{{ row.term }}
						</template>
					</td>
					<td class="traffic-table__number">{{ row.count }}</td>
				</tr>
			</tbody>
		</table>
		<p
			v-if="
				emptyState === ''
				&& part !== 'channels'
				&& summary.searchesWithoutCount > 0
			"
			class="traffic-table__muted"
			data-testid="traffic-searches-without-count">
			{{
				n(
					'portaliq',
					'%n search did not report how many results it found.',
					'%n searches did not report how many results they found.',
					summary.searchesWithoutCount,
				)
			}}
		</p>
		<p
			v-if="emptyState === '' && part === 'searches'"
			class="traffic-table__muted traffic-table__note"
			data-testid="traffic-searches-note">
			{{
				t(
					'portaliq',
					'What visitors typed into the search box of the portal. Counted per term, not per person.',
				)
			}}
		</p>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import TrafficEmptyState from './TrafficEmptyState.vue'
import { searchLinkOf } from '../lib/trafficSummary.js'
import trafficWidgetMixin from './trafficWidgetMixin.js'

export default {
	name: 'TrafficSources',

	components: {
		TrafficEmptyState,
	},

	mixins: [trafficWidgetMixin],

	props: {
		/**
		 * The widget's manifest content. `part` picks what it shows: "all"
		 * (the default: channels, then the searches under them),
		 * "channels" or "searches".
		 */
		content: {
			type: Object,
			default: () => ({}),
		},
	},

	computed: {
		/**
		 * Which part of the sources report this card shows.
		 *
		 * @return {string} "all", "channels" or "searches".
		 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-site-search-must-be-reported-by-the-built-in-search-box
		 */
		part() {
			return ['channels', 'searches'].includes(this.content?.part)
				? this.content.part
				: 'all'
		},

		/**
		 * Whether the searched terms are shown: always on their own card,
		 * under the channels only when there are any.
		 *
		 * @return {boolean} True when the searches table is drawn.
		 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-site-search-must-be-reported-by-the-built-in-search-box
		 */
		showSearches() {
			if (this.part === 'searches') {
				return true
			}
			return this.part === 'all' && this.summary.searches.length > 0
		},

		/**
		 * Whether to show the heading: a list of terms, or only a count of unknown searches.
		 *
		 * @return {boolean} True when there is a zero-result term to list.
		 * @spec openspec/changes/portal-traffic-zero-result-searches/specs/portal-traffic-reporting/spec.md#requirement-the-traffic-page-shows-what-the-public-did-not-find-req-pzr-002
		 */
		showZeroResults() {
			return this.summary.zeroResultSearches.length > 0
		},
	},

	methods: {
		/**
		 * The public search for a term, to see what the visitor saw.
		 *
		 * @param {string} term The term.
		 * @return {string} The URL, or ''.
		 * @spec openspec/changes/portal-traffic-zero-result-searches/specs/portal-traffic-reporting/spec.md#requirement-the-traffic-page-shows-what-the-public-did-not-find-req-pzr-002
		 */
		searchLink(term) {
			return searchLinkOf(this.portal, term, generateUrl)
		},
	},
}
</script>

<style scoped src="./trafficTable.css"></style>
