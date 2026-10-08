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
		<div class="pq-news-block__head">
			<component
				:is="`h${level}`"
				v-if="label"
				:id="headingId"
				class="utrecht-heading-3">
				{{ label }}
			</component>
			<button
				v-if="items.length > 0"
				type="button"
				class="pq-news-block__all"
				data-testid="news-block-all"
				@click="$emit('navigate', 'news')">
				{{ tr('All news') }}
			</button>
		</div>
		<p v-if="feed === null" class="utrecht-paragraph" role="status">
			{{ tr('Loading…') }}
		</p>
		<p
			v-else-if="items.length === 0"
			class="utrecht-paragraph"
			data-testid="news-block-empty">
			<em>{{ tr('No news yet.') }}</em>
		</p>
		<!-- One row per item: who it is for, when, and the title. The body
		     belongs on the news screen, which the title opens
		     (account-news-rows). -->
		<ul v-else class="pq-news-block__rows">
			<li
				v-for="row in rows"
				:key="row.key"
				class="pq-news-block__row"
				data-testid="news-block-row">
				<span class="pq-news-block__meta">
					<DataBadge v-if="row.who" :text="row.who" />
					<span v-if="row.when">{{ row.when }}</span>
				</span>
				<button
					type="button"
					class="pq-news-block__title"
					:lang="row.lang || undefined"
					@click="$emit('navigate', 'news')">
					{{ row.title }}
				</button>
			</li>
		</ul>
	</section>
</template>

<script>
import DataBadge from '../mijn/DataBadge.vue'
import { newestNewsFirst, newsForRecord } from '../../../shared/recordPage.js'
import { withStrings } from '../../pages/inbox/translate.js'
import { hasTranslatedTitle } from '../../pages/inbox/translation.js'
import { longDate } from '../mijn/dates.js'

let counter = 0

/**
 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-news-block-must-show-the-subjects-latest-news
 */
export default {
	name: 'NewsBlock',

	components: { DataBadge },

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

		/**
		 * The rows: who an item is for, its date in the page language and its
		 * title (the translated title when the item carries one), never its
		 * body (account-news-rows).
		 *
		 * @return {Array<{key: string, who: string, when: string, title: string, lang: string}>}
		 * @spec openspec/changes/account-news-rows/specs/portal-contribution-contract/spec.md#requirement-a-news-block-on-an-account-page-shows-rows-not-bodies
		 */
		rows() {
			return this.items.map((item, index) => {
				const titled = hasTranslatedTitle(item?.translation)
				return {
					key: String(item?.id || item?.['@self']?.id || index),
					who: String(item?.audienceLabel || '').trim(),
					when: longDate(
						String(item?.publishedAt || '').slice(0, 10),
						this.locale,
					),
					title: titled
						? item.translation.title
						: String(item?.title || ''),
					lang: titled ? item.translation.targetLanguage || '' : '',
				}
			})
		},

		items() {
			return newestNewsFirst(
				newsForRecord(
					this.feed,
					this.record,
					this.contribution,
					this.groups,
				),
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
/* The board's news rows (MijnOverzicht): tokens only. */
.pq-news-block {
	margin-block-end: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-news-block__head {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	justify-content: space-between;
	gap: 0.75rem;
}

.pq-news-block__head > * {
	margin: 0;
}

.pq-news-block__all,
.pq-news-block__title {
	padding: 0;
	border: 0;
	background: none;
	color: var(--utrecht-link-color, LinkText);
	font: inherit;
	font-weight: 600;
	text-align: start;
	text-decoration: underline;
	text-underline-offset: 3px;
	cursor: pointer;
	min-block-size: 24px;
}

.pq-news-block__all:focus-visible,
.pq-news-block__title:focus-visible {
	outline: 2px solid var(--utrecht-focus-outline-color, currentcolor);
	outline-offset: 2px;
}

.pq-news-block__rows {
	margin: 0.75rem 0 0;
	padding: 0;
	list-style: none;
	border-block-start: 1px solid var(--nldesign-color-border, currentcolor);
}

.pq-news-block__row {
	display: flex;
	flex-direction: column;
	gap: 0.375rem;
	padding-block: 1rem;
	border-block-end: 1px solid var(--nldesign-color-border, currentcolor);
}

.pq-news-block__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.5rem;
	color: var(
		--thematiq-website-text-muted,
		var(--nldesign-color-text-muted, inherit)
	);
	font-size: 0.9375rem;
}

.pq-news-block__title {
	font-size: 1.1875rem;
}
</style>
