<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The newest items of the subject's news feed on a contribution page
	(contribution-record-page). On an open record only the items for its
	school, its groups or the record itself show. The full feed stays in the
	news section, which the link at the bottom opens.
-->
<template>
	<section
		class="pq-news-block"
		:aria-labelledby="label ? headingId : undefined"
		data-testid="news-block">
		<component
			:is="`h${level}`"
			v-if="label"
			:id="headingId"
			class="utrecht-heading-3">
			{{ label }}
		</component>
		<p v-if="feed === null" class="utrecht-paragraph" role="status">
			{{ tr('Loading…') }}
		</p>
		<p
			v-else-if="items.length === 0"
			class="utrecht-paragraph"
			data-testid="news-block-empty">
			<em>{{ tr('No news yet.') }}</em>
		</p>
		<template v-else>
			<NewsItem
				v-for="(item, i) in items"
				:key="item.id || item['@self']?.id || i"
				:item="item"
				:level="level + 1"
				:idPrefix="`${headingId}-item`"
				:t="tr"
				:locale="locale" />
			<button
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				data-testid="news-block-all"
				@click="$emit('navigate', 'news')">
				{{ tr('All news') }}
			</button>
		</template>
	</section>
</template>

<script>
import NewsItem from '../inbox/NewsItem.vue'
import { newsForRecord } from '../../../shared/recordPage.js'
import { withStrings } from '../../pages/inbox/translate.js'

let counter = 0

/**
 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-news-block-must-show-the-subjects-latest-news
 */
export default {
	name: 'NewsBlock',

	components: { NewsItem },

	props: {
		/** The shared portal api (`fetchNewsFeed`). */
		api: { type: Object, default: null },
		/** How many items to show. */
		limit: { type: Number, default: 3 },
		/** The heading's level, 2 or 3, so the page outline stays intact. */
		level: { type: Number, default: 2 },
		/** The heading, '' for none. */
		label: { type: String, default: '' },
		/** The open record, or null. */
		record: { type: Object, default: null },
		/** The contribution, for its `guardianAudience`. */
		contribution: { type: Object, default: null },
		/** The open record's group ids. */
		groups: { type: Array, default: () => [] },
		/** A feed to start from, for a server render or a test. */
		initialFeed: { type: Array, default: null },
		/** The translator. */
		t: { type: Function, required: true },
		/** The page language. */
		locale: { type: String, default: 'nl' },
	},

	emits: ['navigate'],

	data() {
		counter += 1
		return { headingId: `pq-news-block-${counter}`, feed: this.initialFeed }
	},

	computed: {
		/**
		 * The translator, with the news screen's own strings (the AI
		 * translation notice) behind the page's.
		 *
		 * @return {(key: string, vars?: object) => string}
		 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-news-block-must-show-the-subjects-latest-news
		 */
		tr() {
			return withStrings(this.t, this.locale)
		},

		items() {
			return newsForRecord(
				this.feed,
				this.record,
				this.contribution,
				this.groups,
			).slice(0, this.limit)
		},
	},

	mounted() {
		if (this.feed === null) {
			this.load()
		}
	},

	methods: {
		/**
		 * Read the subject's feed; an unreachable feed reads as empty.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-news-block-must-show-the-subjects-latest-news
		 */
		async load() {
			if (!this.api || typeof this.api.fetchNewsFeed !== 'function') {
				this.feed = []
				return
			}
			try {
				const feed = await this.api.fetchNewsFeed()
				this.feed = Array.isArray(feed) ? feed : []
			} catch {
				this.feed = []
			}
		},
	},
}
</script>

<style scoped>
.pq-news-block {
	margin-block-end: var(--utrecht-space-block-lg, 1.5rem);
}
</style>
