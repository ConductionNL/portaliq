<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - Where one article has been used, on the Article page
  - (marketing-articles-from-pipelinq). Self-fetches
  - GET /api/articles/{id}/usages, which asks every app at read time through
  - ArticleUsagesRequestedEvent: portaliq answers for its pages, pipelinq for
  - its templates, blasts and posts. Nothing is written here.
  -
  - Not a declarative object-list widget: the rows come from several apps and
  - schemas joined server-side into one answer, which one object list over
  - one schema cannot express.
  -
  - @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
  -->
<template>
	<div class="article-usage" data-testid="article-usage">
		<NcLoadingIcon v-if="loading" :size="24" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<p v-else-if="groups.length === 0" class="article-usage__empty">
			{{ t('portaliq', 'This article has not been used anywhere yet.') }}
		</p>

		<div v-else class="article-usage__groups">
			<section v-for="group in groups" :key="group.app">
				<h3>{{ appLabel(group.app) }}</h3>
				<ul class="article-usage__list">
					<li
						v-for="item in group.items"
						:key="item.app + ':' + item.kind + ':' + item.id"
						class="article-usage__row">
						<a v-if="item.url" :href="item.url">{{
							item.title || item.id
						}}</a>
						<span v-else>{{ item.title || item.id }}</span>
						<span class="article-usage__kind">{{
							kindLabel(item.kind)
						}}</span>
					</li>
				</ul>
			</section>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { fetchArticleUsages } from '../../lib/articlesApi.js'
import { groupUsages } from '../../lib/articleStatus.js'

/**
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
 */
export default {
	name: 'ArticleUsageSection',

	components: { NcLoadingIcon, NcNoteCard },

	inject: {
		cnSectionContext: { default: null },
	},

	props: {
		/** The article id; empty reads it from the section context or the route. */
		articleId: { type: String, default: '' },
	},

	data() {
		return { loading: false, error: '', rows: [] }
	},

	computed: {
		/**
		 * @return {Array<{app: string, items: Array<object>}>} The usages by app.
		 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
		 */
		groups() {
			return groupUsages(this.rows)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * @return {string} The article id from the prop, the section context or the route.
		 */
		effectiveId() {
			if (this.articleId) {
				return this.articleId
			}
			const ctx = this.cnSectionContext
			const bag =
				ctx && typeof ctx === 'object' && 'value' in ctx ? ctx.value : ctx
			return (
				(bag && (bag.objectId || bag.articleId))
				|| (this.$route && this.$route.params && this.$route.params.id)
				|| ''
			)
		},

		/**
		 * @param {string} app The reporting app.
		 * @return {string} Its name as the reader sees it.
		 */
		appLabel(app) {
			const names = {
				portaliq: t('portaliq', 'Website'),
				pipelinq: t('portaliq', 'Marketing'),
			}
			return names[app] || app || t('portaliq', 'Other apps')
		},

		/**
		 * @param {string} kind The usage kind.
		 * @return {string} The kind in words.
		 */
		kindLabel(kind) {
			const kinds = {
				page: t('portaliq', 'Page'),
				campaignTemplate: t('portaliq', 'Campaign template'),
				template: t('portaliq', 'Campaign template'),
				blast: t('portaliq', 'Mailing'),
				socialPost: t('portaliq', 'Social post'),
			}
			return kinds[kind] || kind || ''
		},

		/**
		 * Read the usages.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
		 */
		async load() {
			const id = this.effectiveId()
			if (!id) {
				return
			}
			this.loading = true
			this.error = ''
			try {
				const body = await fetchArticleUsages(id)
				this.rows = Array.isArray(body) ? body : body?.data || []
			} catch {
				this.error = t(
					'portaliq',
					'Where this article is used could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.article-usage__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.article-usage__row {
	display: flex;
	justify-content: space-between;
	gap: 1rem;
	padding-block: 0.5rem;
	border-block-end: 1px solid var(--color-border);
}

.article-usage__kind,
.article-usage__empty {
	color: var(--color-text-maxcontrast);
}
</style>
