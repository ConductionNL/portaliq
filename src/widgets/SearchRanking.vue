<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  SearchRanking: "How search ranks", on the portal's own page
  (search-sort-by-relevance REQ-SSR-006). Rendered from the one ranking
  declaration the site's search request is built from, so the explanation
  says what the search does, not what someone once wrote about it.

  @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-the-publisher-can-read-how-search-ranks-req-ssr-006
-->
<template>
	<div class="search-ranking" data-testid="search-ranking">
		<ul>
			<li v-for="line in lines" :key="line">
				{{ line }}
			</li>
		</ul>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { RANKING, rankingExplanation } from '../site/lib/federatedSearch.js'

export default {
	name: 'SearchRanking',

	// The detail page hands every widget its object; the ranking is the same
	// for every portal, so it is not read, and not rendered as an attribute.
	inheritAttrs: false,

	computed: {
		/**
		 * The explanation, sentence by sentence.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-the-publisher-can-read-how-search-ranks-req-ssr-006
		 */
		lines() {
			return rankingExplanation(RANKING, (key) => t('portaliq', key))
		},
	},
}
</script>

<style scoped>
.search-ranking ul {
	list-style: disc;
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
}
</style>
