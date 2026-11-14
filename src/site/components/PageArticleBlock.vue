<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The article a markdown page names (marketing-articles-from-pipelinq): its
	title, hero image and body, rendered in place so a campaign page carries
	no copy of the text. The content API sends only a published or archived
	article; anything else arrives as null and renders nothing.
-->
<template>
	<section
		v-if="article && (article.title || article.markdown)"
		class="pq-page-article"
		:aria-label="article.title || undefined"
		data-testid="page-article">
		<h2 v-if="article.title" class="utrecht-heading-2 pq-page-article__title">
			{{ article.title }}
		</h2>
		<img
			v-if="article.hero && article.hero.url"
			class="pq-page-article__hero"
			data-testid="page-article-hero"
			:src="article.hero.url"
			:alt="article.hero.alt || ''" />
		<MarkdownBlock :source="article.markdown || ''" />
	</section>
</template>

<script>
import MarkdownBlock from './MarkdownBlock.vue'

/**
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-a-page-can-show-an-article
 */
export default {
	name: 'PageArticleBlock',

	components: { MarkdownBlock },

	props: {
		/** The article from the content API: `{title, hero, markdown}`, or null. */
		article: { type: Object, default: null },
	},
}
</script>

<style scoped>
.pq-page-article {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	margin-block-start: 2rem;
}

.pq-page-article__hero {
	max-inline-size: 100%;
	block-size: auto;
	border-radius: var(--utrecht-border-radius-md, 0.75rem);
}
</style>
