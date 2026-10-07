<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The news staff put on this portal's website (site-school-blocks).

	The newest item can lead as a card with its photo and first paragraph;
	the rest are rows of date and title. `compact` is the aside version: rows
	only, for "related news" beside an article, where the item on screen is
	left out. Which items exist is the server's decision (PublicNewsReader);
	a placement chooses only how many and how they look.
-->
<template>
	<section
		class="nl-news-list"
		:class="`nl-news-list--${mode}`"
		data-testid="nl-news-list">
		<div v-if="heading || more" class="nl-news-list__head">
			<h2
				v-if="heading"
				:class="
					mode === 'compact' ? 'utrecht-heading-3' : 'utrecht-heading-2'
				">
				{{ heading }}
			</h2>
			<a
				v-if="more"
				class="utrecht-link nl-news-list__more"
				:href="more.href"
				@click="open($event, more)">
				{{ moreLabel }}
			</a>
		</div>

		<p
			v-if="state === 'loading'"
			class="utrecht-paragraph nl-news-list__status"
			role="status">
			{{ say('loading') }}
		</p>
		<p
			v-else-if="state === 'failed'"
			class="utrecht-paragraph nl-news-list__status"
			role="status">
			{{ say('failed') }}
		</p>
		<p
			v-else-if="shown.length === 0"
			class="utrecht-paragraph nl-news-list__status"
			data-testid="nl-news-list-empty">
			{{ say('empty') }}
		</p>

		<template v-else>
			<article
				v-if="lead"
				class="nl-news-list__lead"
				data-testid="nl-news-lead">
				<img
					v-if="lead.image"
					class="nl-news-list__photo"
					:src="lead.image.url"
					:alt="lead.image.alt"
					loading="lazy" />
				<!-- No photo yet: a quiet block that holds the photo's place, so
				     the lead keeps the board's shape (site-matches-the-zuiddrecht-boards). -->
				<div
					v-else-if="leadPlaceholder"
					class="nl-news-list__photo nl-news-list__photo--placeholder"
					aria-hidden="true"
					data-testid="nl-news-lead-placeholder">
					{{ leadPlaceholder }}
				</div>
				<div class="nl-news-list__lead-text">
					<span class="nl-news-list__meta">{{ metaOf(lead) }}</span>
					<h3 class="utrecht-heading-3 nl-news-list__lead-title">
						<a
							class="utrecht-link"
							:href="linkOf(lead).href"
							@click="open($event, linkOf(lead))"
							>{{ lead.title }}</a
						>
					</h3>
					<p v-if="lead.intro" class="utrecht-paragraph">
						{{ lead.intro }}
					</p>
				</div>
			</article>
			<ul v-if="rows.length > 0" class="nl-news-list__rows">
				<li
					v-for="item in rows"
					:key="item.id"
					class="nl-news-list__row"
					data-testid="nl-news-row">
					<span class="nl-news-list__meta nl-news-list__row-meta">{{
						metaOf(item)
					}}</span>
					<a
						class="utrecht-link nl-news-list__row-title"
						:href="linkOf(item).href"
						@click="open($event, linkOf(item))">
						{{ item.title }}
					</a>
				</li>
			</ul>
		</template>
	</section>
</template>

<script>
import { longDate } from '../../components/mijn/dates.js'
import { authoredLink, staysInSite } from '../../components/mijn/links.js'
import { fetchPublicNews } from '../../lib/publicNews.js'
import { pageLocale } from '../../pages/inbox/translate.js'
import strings from './strings.js'

import '@utrecht/heading-2-css/dist/index.css'
import '@utrecht/heading-3-css/dist/index.css'
import '@utrecht/link-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

/**
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-list-shows-the-news-staff-put-on-the-website
 */
export default {
	name: 'NlNewsList',

	props: {
		/** The heading. */
		heading: { type: String, default: '' },
		/** How many items, 1 to 12. */
		limit: { type: [Number, String], default: 4 },
		/** The newest item as a card with photo and intro. */
		featured: { type: Boolean, default: true },
		/** Words in the photo's place while the lead has no photo; empty draws nothing. */
		leadPlaceholder: { type: String, default: '' },
		/** `list` or `compact`. */
		display: { type: String, default: 'list' },
		/** Show who an item is for beside its date. */
		showAudience: { type: Boolean, default: true },
		/** The link to all news. */
		moreLabel: { type: String, default: '' },
		/** Its address. */
		moreHref: { type: String, default: '' },
		/** Where an item opens: `<articleRoute>/<id>`. */
		articleRoute: { type: String, default: '/nieuws' },
		/** The serving portal, from the host. */
		portal: { type: String, default: '' },
		/** The item on screen (an article page), from the host; left out of the list. */
		routeParam: { type: String, default: '' },
	},

	emits: ['navigate'],

	data() {
		return { items: [], state: 'loading' }
	},

	computed: {
		/**
		 * @return {string} `list` or `compact`.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-list-shows-the-news-staff-put-on-the-website
		 */
		mode() {
			return this.display === 'compact' ? 'compact' : 'list'
		},

		/**
		 * @return {number} The count asked for, 1 to 12.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-list-shows-the-news-staff-put-on-the-website
		 */
		count() {
			const count = Math.trunc(Number(this.limit))
			return Number.isFinite(count) ? Math.min(12, Math.max(1, count)) : 4
		},

		/**
		 * The items to show: the one on screen left out, then the count.
		 *
		 * @return {Array<object>} The items.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-list-shows-the-news-staff-put-on-the-website
		 */
		shown() {
			return this.items
				.filter((item) => item && item.id && item.id !== this.routeParam)
				.slice(0, this.count)
		},

		/**
		 * @return {object|null} The leading item, when it leads.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-list-shows-the-news-staff-put-on-the-website
		 */
		lead() {
			return this.featured && this.mode === 'list'
				? this.shown[0] || null
				: null
		},

		/**
		 * @return {Array<object>} The rows under the lead.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-list-shows-the-news-staff-put-on-the-website
		 */
		rows() {
			return this.lead ? this.shown.slice(1) : this.shown
		},

		/**
		 * @return {object|null} The link to all news.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-list-shows-the-news-staff-put-on-the-website
		 */
		more() {
			return this.moreLabel.trim() ? authoredLink(this.moreHref) : null
		},
	},

	/**
	 * Read the news once the widget is on the page.
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-list-shows-the-news-staff-put-on-the-website
	 */
	async mounted() {
		try {
			// One more than shown, so leaving out the item on screen still fills the list.
			this.items = await fetchPublicNews(
				this.portal,
				Math.min(12, this.count + 1),
			)
			this.state = 'ready'
		} catch {
			this.state = 'failed'
		}
	},

	methods: {
		/**
		 * @param {string} key A string key.
		 * @return {string} The words in the page language.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-list-shows-the-news-staff-put-on-the-website
		 */
		say(key) {
			return (strings[pageLocale()] || strings.nl)[key]
		},

		/**
		 * "2 oktober 2026 · hele school".
		 *
		 * @param {object} item The item.
		 * @return {string} The meta line.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-list-shows-the-news-staff-put-on-the-website
		 */
		metaOf(item) {
			return [
				longDate(item.publishedAt),
				this.showAudience ? item.audienceLabel : '',
			]
				.filter(Boolean)
				.join(' · ')
		},

		/**
		 * @param {object} item The item.
		 * @return {object} Its link: the article route with its id.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-list-shows-the-news-staff-put-on-the-website
		 */
		linkOf(item) {
			const base = /^\/(?!\/)/.test(this.articleRoute)
				? this.articleRoute.replace(/\/+$/, '')
				: '/nieuws'
			return authoredLink(`${base}/${encodeURIComponent(item.id)}`)
		},

		/**
		 * @param {MouseEvent} event The click.
		 * @param {object} link The link.
		 * @return {void}
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-list-shows-the-news-staff-put-on-the-website
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
.nl-news-list {
	display: flex;
	flex-direction: column;
	gap: 1.5rem;
}

.nl-news-list__head {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: baseline;
	gap: 0.75rem;
}

.nl-news-list__head h2 {
	margin: 0;
}

.nl-news-list__more {
	font-weight: 600;
}

.nl-news-list__lead {
	display: flex;
	flex-wrap: wrap;
	gap: 1.5rem;
}

.nl-news-list__photo {
	flex: 1 1 18.75rem;
	min-block-size: 14.375rem;
	max-inline-size: 100%;
	object-fit: cover;
	border-radius: var(
		--nldesign-website-border-radius-large,
		var(--utrecht-border-radius-md, 0.75rem)
	);
	background: var(--nldesign-color-primary-light, transparent);
}

.nl-news-list__photo--placeholder {
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(
		--nldesign-color-primary-hover,
		var(--utrecht-document-color, CanvasText)
	);
	font-size: 0.9375rem;
}

.nl-news-list__lead-text {
	flex: 1 1 18.75rem;
	display: flex;
	flex-direction: column;
	justify-content: center;
	gap: 0.625rem;
}

.nl-news-list__lead-title {
	margin: 0;
}

.nl-news-list__lead-title a {
	color: var(--utrecht-document-color, CanvasText);
}

.nl-news-list__lead-text p {
	margin: 0;
}

.nl-news-list__meta {
	color: var(
		--nldesign-color-text-muted,
		var(--utrecht-document-color, CanvasText)
	);
	font-size: 0.9375rem;
}

.nl-news-list__rows {
	margin: 0;
	padding: 0;
	list-style: none;
	border-block-start: 1px solid
		var(--nldesign-color-border-dark, var(--utrecht-color-grey-80, currentcolor));
}

.nl-news-list__row {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 0.375rem 1.5rem;
	padding-block: 1.125rem;
	border-block-end: 1px solid
		var(--nldesign-color-border-dark, var(--utrecht-color-grey-80, currentcolor));
}

.nl-news-list__row-meta {
	flex: 0 0 10.625rem;
}

.nl-news-list__row-title {
	flex: 1 1 18.75rem;
	font-size: 1.1875rem;
	font-weight: 600;
}

.nl-news-list--compact .nl-news-list__row {
	flex-direction: column;
	gap: 0.25rem;
	padding-block: 0.875rem;
}

.nl-news-list--compact .nl-news-list__row-meta,
.nl-news-list--compact .nl-news-list__row-title {
	flex: none;
	font-size: inherit;
}

.nl-news-list__status {
	margin: 0;
}
</style>
