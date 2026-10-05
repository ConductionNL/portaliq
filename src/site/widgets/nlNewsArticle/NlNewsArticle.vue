<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	One public news item, chosen by the route: `/nieuws/<id>` on a page at
	`/nieuws` (site-school-blocks). Which item it shows is the ROUTE's
	decision, not the placement's, so the host hands the id over and a page
	cannot pin itself to one item. An id that is not a public item of this
	portal reads as "not found", never as an error.

	The body is the item's own markdown, rendered by the site's sanitising
	markdown block; its first paragraph is the lead.
-->
<template>
	<article class="nl-news-article" data-testid="nl-news-article">
		<p v-if="state === 'loading'" class="utrecht-paragraph" role="status">
			{{ say('loading') }}
		</p>
		<p v-else-if="state === 'failed'" class="utrecht-paragraph" role="status">
			{{ say('failed') }}
		</p>
		<template v-else-if="state === 'missing'">
			<p class="utrecht-paragraph" data-testid="nl-news-article-missing">
				{{ say('notFound') }}
			</p>
			<p class="utrecht-paragraph">
				<a
					class="utrecht-link"
					:href="back.href"
					@click="open($event, back)"
					>{{ backLabel || say('back') }}</a
				>
			</p>
		</template>
		<template v-else>
			<header class="nl-news-article__head">
				<p class="nl-news-article__meta">
					<span v-if="kindLabel" class="nl-news-article__kind">{{
						kindLabel
					}}</span>
					<span>{{ meta }}</span>
				</p>
				<h1 class="utrecht-heading-1 nl-news-article__title">
					{{ item.title }}
				</h1>
				<p v-if="parts.lead" class="utrecht-paragraph nl-news-article__lead">
					{{ parts.lead }}
				</p>
			</header>
			<img
				v-if="item.image"
				class="nl-news-article__photo"
				:src="item.image.url"
				:alt="item.image.alt" />
			<MarkdownBlock
				v-if="parts.rest"
				class="nl-news-article__body"
				:source="parts.rest" />
		</template>
	</article>
</template>

<script>
import MarkdownBlock from '../../components/MarkdownBlock.vue'
import { longDate } from '../../components/mijn/dates.js'
import { authoredLink, staysInSite } from '../../components/mijn/links.js'
import { fetchPublicNewsItem } from '../../lib/publicNews.js'
import { pageLocale } from '../../pages/inbox/translate.js'
import strings from '../nlNewsList/strings.js'
import { splitLead } from './article.js'

import '@utrecht/heading-1-css/dist/index.css'
import '@utrecht/link-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

/**
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-article-page-shows-one-public-item-chosen-by-the-route
 */
export default {
	name: 'NlNewsArticle',

	components: { MarkdownBlock },

	props: {
		/** A small label above the title, such as "Nieuws". */
		kindLabel: { type: String, default: '' },
		/** The words of the link back when the item is not found. */
		backLabel: { type: String, default: '' },
		/** Where that link goes. */
		backHref: { type: String, default: '/nieuws' },
		/** The serving portal, from the host. */
		portal: { type: String, default: '' },
		/** The item id, from the route, from the host. */
		routeParam: { type: String, default: '' },
	},

	emits: ['navigate'],

	data() {
		return { item: null, state: 'loading' }
	},

	computed: {
		/**
		 * @return {{lead: string, rest: string}} The lead and the body.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-article-page-shows-one-public-item-chosen-by-the-route
		 */
		parts() {
			return splitLead(this.item?.body)
		},

		/**
		 * @return {string} "2 oktober 2026 · hele school".
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-article-page-shows-one-public-item-chosen-by-the-route
		 */
		meta() {
			return [longDate(this.item?.publishedAt), this.item?.audienceLabel]
				.filter(Boolean)
				.join(' · ')
		},

		/**
		 * @return {object} The link back.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-article-page-shows-one-public-item-chosen-by-the-route
		 */
		back() {
			return authoredLink(this.backHref) || authoredLink('/nieuws')
		},
	},

	watch: {
		routeParam: 'load',
	},

	/**
	 * Read the news once the widget is on the page.
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-article-page-shows-one-public-item-chosen-by-the-route
	 */
	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the item the route names.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-article-page-shows-one-public-item-chosen-by-the-route
		 */
		async load() {
			if (!this.routeParam) {
				this.state = 'missing'
				return
			}
			this.state = 'loading'
			try {
				this.item = await fetchPublicNewsItem(this.portal, this.routeParam)
				this.state = this.item ? 'ready' : 'missing'
			} catch {
				this.state = 'failed'
			}
		},

		/**
		 * @param {string} key A string key.
		 * @return {string} The words in the page language.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-article-page-shows-one-public-item-chosen-by-the-route
		 */
		say(key) {
			return (strings[pageLocale()] || strings.nl)[key]
		},

		/**
		 * @param {MouseEvent} event The click.
		 * @param {object} link The link.
		 * @return {void}
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-article-page-shows-one-public-item-chosen-by-the-route
		 */
		open(event, link) {
			if (staysInSite(event, link)) {
				event.preventDefault()
				this.$emit('navigate', link.route)
			}
		},
	},
}
</script>

<style scoped>
.nl-news-article {
	display: flex;
	flex-direction: column;
	gap: 1.625rem;
	max-inline-size: 51.25rem;
}

.nl-news-article__head {
	display: flex;
	flex-direction: column;
	gap: 0.875rem;
}

.nl-news-article__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.625rem;
	margin: 0;
	color: var(
		--nldesign-color-text-muted,
		var(--utrecht-document-color, CanvasText)
	);
}

.nl-news-article__kind {
	padding: 0.25rem 0.75rem;
	border-radius: 999px;
	background: var(--nldesign-color-primary-light, transparent);
	color: var(
		--nldesign-color-primary-hover,
		var(--utrecht-document-color, CanvasText)
	);
	font-size: 0.9375rem;
	font-weight: 600;
}

.nl-news-article__title {
	margin: 0;
}

.nl-news-article__lead {
	margin: 0;
	font-size: 1.3125rem;
	line-height: 1.6;
}

.nl-news-article__photo {
	inline-size: 100%;
	max-block-size: 30rem;
	object-fit: cover;
	border-radius: var(
		--nldesign-website-border-radius-large,
		var(--utrecht-border-radius-md, 0.75rem)
	);
}
</style>
