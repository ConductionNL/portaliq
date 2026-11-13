<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  PortalSearchRanking: "How search ranks", in the portal's settings
  (search-sort-by-relevance REQ-SSR-006).

  Rendered from RANKING in src/site/lib/federatedSearch.js, the same
  declaration the public search block builds its request from, so what a
  publisher reads here is what the search asks for. Nothing is fetched.

  @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-the-publisher-can-read-how-search-ranks-req-ssr-006
-->
<template>
	<div class="portal-search-ranking" data-testid="portal-search-ranking">
		<p v-for="(sentence, index) in sentences" :key="index">
			{{ sentence }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { RANKING, rankingExplanation } from '../site/lib/federatedSearch.js'

export default {
	name: 'PortalSearchRanking',

	computed: {
		/**
		 * The explanation, translated.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-the-publisher-can-read-how-search-ranks-req-ssr-006
		 */
		sentences() {
			return rankingExplanation(RANKING, (text) => t('portaliq', text))
		},
	},
}
</script>

<style scoped>
.portal-search-ranking p {
	margin-block: 0 calc(var(--default-grid-baseline) * 2);
}
</style>
