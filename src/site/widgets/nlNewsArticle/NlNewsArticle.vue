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
			<section
				v-if="cardState !== 'none'"
				class="nl-news-article__event"
				:aria-label="item.event.title"
				data-testid="nl-news-article-event">
				<dl class="nl-news-article__facts">
					<div v-for="fact in facts" :key="fact.label">
						<dt>{{ fact.label }}</dt>
						<dd>{{ fact.value }}</dd>
					</div>
				</dl>
				<p
					v-if="cardState === 'closed'"
					class="utrecht-paragraph"
					role="status"
					data-testid="nl-news-article-event-closed">
					{{ closedLine }}
				</p>
				<template v-else>
					<p v-if="cardState === 'signin'" class="utrecht-paragraph">
						{{ say('eventSignInFirst', { area }) }}
					</p>
					<p v-if="item.event.askSeats" class="utrecht-paragraph">
						{{ say('eventSeats', { count: item.event.maxSeatsPerAnswer || 4 }) }}
					</p>
					<a
						class="utrecht-button utrecht-button--primary-action"
						:href="button.href"
						data-testid="nl-news-article-event-button"
						@click="openButton($event)"
						>{{ buttonLabel }}</a
					>
				</template>
			</section>
		</template>
	</article>
</template>

<script>
import MarkdownBlock from '../../components/MarkdownBlock.vue'
import { longDate } from '../../components/mijn/dates.js'
import { authoredLink, staysInSite } from '../../components/mijn/links.js'
import { fetchPublicNewsItem } from '../../lib/publicNews.js'
import { interpolate, pageLocale } from '../../pages/inbox/translate.js'
import strings from '../nlNewsList/strings.js'
import { cardButton, safeWays } from '../nlSignIn/signIn.js'
import { areaName, eventCardState, splitLead } from './article.js'

import '@utrecht/heading-1-css/dist/index.css'
import '@utrecht/link-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

/**
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-article-page-shows-one-public-item-chosen-by-the-route
 */
export default {
	name: 'NlNewsArticle',

	components: { MarkdownBlock },

	inject: {
		/**
		 * The language of the page's content (site-dates-in-content-language);
		 * empty outside the site shell, so the document's language applies.
		 */
		contentLocale: { from: 'siteContentLocale', default: () => () => '' },
	},

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
		/** Whether the visitor holds a session, from the host. */
		signedIn: { type: Boolean, default: false },
		/** The portal's ways in, from the host: `{id, label, href}`. */
		ways: { type: Array, default: () => [] },
		/** The name of the resident area ("Mijn Vaartveld"); empty derives it from the portal. */
		areaLabel: { type: String, default: '' },
		/** The sign-up button's words when the visitor is signed in. */
		signUpLabel: { type: String, default: 'Aanmelden' },
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
			return [
				longDate(this.item?.publishedAt, this.contentLocale()),
				this.item?.audienceLabel,
			]
				.filter(Boolean)
				.join(' · ')
		},

		/**
		 * @return {('none'|'closed'|'signin'|'open')} What the sign-up card does.
		 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/specs/portaliq-cms/spec.md#requirement-a-news-item-may-carry-the-sign-up-of-its-event
		 */
		cardState() {
			return eventCardState(this.item?.event, this.signedIn)
		},

		/**
		 * @return {string} The resident area's name.
		 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/specs/portaliq-cms/spec.md#requirement-a-news-item-may-carry-the-sign-up-of-its-event
		 */
		area() {
			return areaName(this.areaLabel, this.portal)
		},

		/**
		 * @return {Array<{label: string, value: string}>} When, where, for whom, deadline.
		 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/specs/portaliq-cms/spec.md#requirement-a-news-item-may-carry-the-sign-up-of-its-event
		 */
		facts() {
			const event = this.item?.event
			if (!event) {
				return []
			}
			const locale = this.contentLocale()
			const deadline = longDate(event.signupDeadline, locale)
			return [
				{ label: this.say('eventWhen'), value: longDate(event.start, locale) },
				{ label: this.say('eventWhere'), value: event.location || '' },
				{ label: this.say('eventForWhom'), value: this.item?.audienceLabel || '' },
				{
					label: this.say('eventDeadline'),
					value: deadline ? this.say('eventUntil', { date: deadline }) : '',
				},
			].filter((fact) => fact.value)
		},

		/**
		 * @return {string} "Aanmelden kon tot en met vrijdag 30 oktober."
		 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/specs/portaliq-cms/spec.md#requirement-a-news-item-may-carry-the-sign-up-of-its-event
		 */
		closedLine() {
			const date = longDate(this.item?.event?.signupDeadline, this.contentLocale())
			return date ? this.say('eventClosed', { date }) : this.say('eventClosedNoDate')
		},

		/**
		 * @return {{label: string, href: string, route: string}} The card's button: sign in, or on to the resident area.
		 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/specs/portaliq-cms/spec.md#requirement-a-news-item-may-carry-the-sign-up-of-its-event
		 */
		button() {
			const button = cardButton({
				ways: safeWays(this.ways),
				signedIn: this.signedIn,
				heading: '',
				buttonLabel: '',
				signInHref: '/mijn',
				say: (key) => (key === 'ownArea' ? this.signUpLabel : this.say('eventSignIn')),
			})
			return button.route ? { ...button, href: authoredLink(button.route).href } : button
		},

		/**
		 * @return {string} The button's words.
		 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/specs/portaliq-cms/spec.md#requirement-a-news-item-may-carry-the-sign-up-of-its-event
		 */
		buttonLabel() {
			return this.signedIn ? this.signUpLabel : this.button.label
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
		 * @param {object} [vars] Placeholders.
		 * @return {string} The words in the page language.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-article-page-shows-one-public-item-chosen-by-the-route
		 */
		say(key, vars) {
			return interpolate((strings[pageLocale()] || strings.nl)[key], vars)
		},

		/**
		 * A plain click on the card's button to a route in the site stays in
		 * the site; a way in is a real navigation to the sign-in edge.
		 *
		 * @param {MouseEvent} event The click.
		 * @return {void}
		 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/specs/portaliq-cms/spec.md#requirement-a-news-item-may-carry-the-sign-up-of-its-event
		 */
		openButton(event) {
			if (staysInSite(event, this.button)) {
				event.preventDefault()
				this.$emit('navigate', this.button.route)
			}
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
.nl-news-article__event {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	padding: 1.25rem;
	border-radius: var(--utrecht-border-radius-md, 0.75rem);
	background: var(--nldesign-color-primary-light, transparent);
}

.nl-news-article__facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 0.25rem 1rem;
	margin: 0;
}

.nl-news-article__facts > div {
	display: contents;
}

.nl-news-article__facts dt {
	font-weight: 700;
}

.nl-news-article__facts dd {
	margin: 0;
}

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
		--thematiq-website-text-muted,
		var(--nldesign-color-text-muted, var(--utrecht-document-color, CanvasText))
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
