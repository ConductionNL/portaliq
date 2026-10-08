<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The page of one item of an app's public index
	(public-detail-page-for-a-provider-item): the facts, the sections of text
	the app projects, the dates to choose from with their places, the
	documents, a secondary link and one action card. The slug comes from the
	route, the content from the app; an item the index does not return reads
	as "not found".

	An action that needs sign-in shows its fields and a sign-in button for a
	visitor who is not signed in. The chosen date and count wait in session
	storage and come back with the visitor after the sign-in.
-->
<template>
	<article class="nl-public-detail" data-testid="nl-public-detail">
		<p v-if="state === 'loading'" class="utrecht-paragraph" role="status">
			{{ say('loading') }}
		</p>
		<p v-else-if="state === 'failed'" class="utrecht-paragraph" role="status">
			{{ say('failed') }}
		</p>
		<template v-else-if="state === 'missing'">
			<p class="utrecht-paragraph" data-testid="nl-public-detail-missing">
				{{ say('notFound') }}
			</p>
			<p class="utrecht-paragraph">
				<a
					class="utrecht-link"
					:href="back.href"
					@click="openBack($event)"
					>{{ backLabel || say('back') }}</a
				>
			</p>
		</template>
		<template v-else>
			<h1 class="utrecht-heading-1">{{ title }}</h1>
			<dl v-if="detail.facts.length > 0" class="nl-public-detail__facts" data-testid="nl-public-detail-facts">
				<div v-for="fact in detail.facts" :key="fact.label">
					<dt>{{ fact.label }}</dt>
					<dd>{{ fact.value }}</dd>
				</div>
			</dl>
			<section v-for="section in detail.sections" :key="section.heading" class="nl-public-detail__section">
				<h2 class="utrecht-heading-2">{{ section.heading }}</h2>
				<MarkdownBlock :source="section.markdown" />
			</section>
			<fieldset
				v-if="detail.dates.length > 0"
				class="nl-public-detail__dates"
				data-testid="nl-public-detail-dates">
				<legend class="utrecht-heading-2">{{ say('dates') }}</legend>
				<label
					v-for="entry in detail.dates"
					:key="entry.id"
					class="nl-public-detail__date"
					:class="{ 'nl-public-detail__date--selected': chosenDate === entry.id }">
					<input
						v-model="chosenDate"
						type="radio"
						name="nl-public-detail-date"
						:value="entry.id"
						:disabled="!choosable(entry)"
						@change="keep()" />
					<span class="nl-public-detail__date-label">{{ dateLabel(entry) }}</span>
					<span v-if="placesText(entry)" class="nl-public-detail__places">{{
						placesText(entry)
					}}</span>
				</label>
			</fieldset>
			<section v-if="detail.documents.length > 0" class="nl-public-detail__section">
				<h2 class="utrecht-heading-2">{{ say('documents') }}</h2>
				<ul>
					<li v-for="document in detail.documents" :key="document.href">
						<a class="utrecht-link" :href="document.href">{{ document.label }}</a>
					</li>
				</ul>
			</section>
			<section
				v-if="detail.action"
				class="nl-public-detail__action"
				data-testid="nl-public-detail-action">
				<label v-if="detail.action.countLabel" class="nl-public-detail__count">
					<span>{{ detail.action.countLabel }}</span>
					<input
						v-model.number="count"
						type="number"
						min="1"
						:max="detail.action.countMax || 20"
						data-testid="nl-public-detail-count"
						@change="onCount()" />
				</label>
				<p v-if="needsSignIn" class="utrecht-paragraph">
					{{ say('signInFirst', { area }) }}
				</p>
				<a
					class="utrecht-button utrecht-button--primary-action"
					:href="actionButton.href"
					data-testid="nl-public-detail-action-button"
					@click="openAction($event)"
					>{{ actionButton.label }}</a
				>
			</section>
			<p v-if="detail.secondaryLink" class="utrecht-paragraph">
				<a
					class="utrecht-button utrecht-button--secondary-action"
					:href="detail.secondaryLink.href"
					data-testid="nl-public-detail-secondary"
					>{{ detail.secondaryLink.label }}</a
				>
			</p>
		</template>
	</article>
</template>

<script>
import MarkdownBlock from '../../components/MarkdownBlock.vue'
import { longDate } from '../../components/mijn/dates.js'
import { authoredLink, staysInSite } from '../../components/mijn/links.js'
import { fetchCatalogueDetail } from '../../lib/publicCatalogue.js'
import { interpolate, pageLocale } from '../../pages/inbox/translate.js'
import { areaName } from '../nlNewsArticle/article.js'
import { cardButton, safeWays } from '../nlSignIn/signIn.js'
import {
	clampCount,
	isChoosable,
	keepChoice,
	placesLine,
	takeChoice,
} from './detail.js'
import strings from './strings.js'

import '@utrecht/button-css/dist/index.css'
import '@utrecht/heading-1-css/dist/index.css'
import '@utrecht/heading-2-css/dist/index.css'
import '@utrecht/link-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

/**
 * Where the visitor's choices wait, or null where there is no storage.
 *
 * @return {Storage|null} The session storage.
 */
function sessionStore() {
	try {
		return typeof window !== 'undefined' ? window.sessionStorage : null
	} catch {
		return null
	}
}

/**
 * The page's path, or '' where there is no window.
 *
 * @return {string} The path.
 */
function pagePath() {
	return typeof window !== 'undefined' && window.location ? window.location.pathname : ''
}

/**
 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-3
 */
export default {
	name: 'NlPublicDetail',

	components: { MarkdownBlock },

	inject: {
		contentLocale: { from: 'siteContentLocale', default: () => () => '' },
	},

	props: {
		/** The app whose public index holds the item, e.g. `learniq`. */
		app: { type: String, default: '' },
		/** The item's type in the index, e.g. `course`. */
		kind: { type: String, default: '' },
		/** The words of the link back. */
		backLabel: { type: String, default: '' },
		/** The address of the link back. */
		backHref: { type: String, default: '/' },
		/** The name of the resident area ("Mijn Academie"); empty derives it from the portal. */
		areaLabel: { type: String, default: '' },
		/** The serving portal, from the host. */
		portal: { type: String, default: '' },
		/** The item's slug, from the route, from the host. */
		routeParam: { type: String, default: '' },
		/** Whether the visitor holds a session, from the host. */
		signedIn: { type: Boolean, default: false },
		/** The portal's ways in, from the host: `{id, label, href}`. */
		ways: { type: Array, default: () => [] },
	},

	emits: ['navigate'],

	data() {
		return { item: null, detail: null, state: 'loading', chosenDate: '', count: 1 }
	},

	computed: {
		/**
		 * @return {string} The page's heading: the app's, else the index item's.
		 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-3
		 */
		title() {
			return this.detail?.title || this.item?.title || ''
		},

		/**
		 * @return {string} The resident area's name.
		 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-4
		 */
		area() {
			return areaName(this.areaLabel, this.portal)
		},

		/**
		 * @return {boolean} Whether the action asks the visitor to sign in first.
		 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-4
		 */
		needsSignIn() {
			return this.detail?.action?.requiresSignIn !== false && this.signedIn !== true
		},

		/**
		 * @return {{label: string, href: string, route: string}} The action card's one button.
		 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-4
		 */
		actionButton() {
			const action = this.detail?.action
			if (this.needsSignIn) {
				const button = cardButton({
					ways: safeWays(this.ways),
					signedIn: false,
					heading: '',
					buttonLabel: this.say('signIn'),
					signInHref: '/mijn',
					say: () => this.say('signIn'),
				})
				return button.route ? { ...button, href: authoredLink(button.route).href } : button
			}
			return {
				label: action?.label || this.say('goOn', { area: this.area }),
				href: authoredLink('/mijn').href,
				route: '/mijn',
			}
		},

		/**
		 * @return {object} The link back.
		 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-3
		 */
		back() {
			return authoredLink(this.backHref) || authoredLink('/')
		},
	},

	watch: {
		routeParam: 'load',
	},

	/**
	 * Read the item once the widget is on the page.
	 *
	 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-3
	 */
	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the item the route names, then take back what the visitor
		 * had chosen before signing in.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-3
		 */
		async load() {
			if (!this.routeParam || !this.app || !this.kind) {
				this.state = 'missing'
				return
			}
			this.state = 'loading'
			try {
				const found = await fetchCatalogueDetail(this.portal, {
					app: this.app,
					kind: this.kind,
					slug: this.routeParam,
				})
				if (!found) {
					this.state = 'missing'
					return
				}
				this.item = found.item
				this.detail = found.detail
				this.state = 'ready'
				const kept = takeChoice(
					sessionStore(),
					pagePath(),
					found.detail.dates,
					found.detail.action?.countMax,
				)
				if (kept) {
					this.chosenDate = kept.date
					this.count = kept.count
				}
			} catch {
				this.state = 'failed'
			}
		},

		/**
		 * @param {string} key A string key.
		 * @param {object} [vars] Placeholders.
		 * @return {string} The words in the page language.
		 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-3
		 */
		say(key, vars) {
			return interpolate((strings[pageLocale()] || strings.nl)[key], vars)
		},

		/**
		 * @param {object} entry A date.
		 * @return {string} The date's card heading: the app's label, else the day.
		 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-3
		 */
		dateLabel(entry) {
			return entry.label || longDate(entry.date, this.contentLocale())
		},

		/**
		 * @param {object} entry A date.
		 * @return {string} Its places line.
		 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-3
		 */
		placesText(entry) {
			return placesLine(entry, (key, vars) => this.say(key, vars))
		},

		/**
		 * @param {object} entry A date.
		 * @return {boolean} Whether it can be chosen.
		 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-3
		 */
		choosable(entry) {
			return isChoosable(entry)
		},

		/**
		 * The count stays inside what the action allows.
		 *
		 * @return {void}
		 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-4
		 */
		onCount() {
			this.count = clampCount(this.count, this.detail?.action?.countMax)
			this.keep()
		},

		/**
		 * Keep the choices while the visitor signs in.
		 *
		 * @return {void}
		 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-4
		 */
		keep() {
			keepChoice(sessionStore(), pagePath(), { date: this.chosenDate, count: clampCount(this.count, this.detail?.action?.countMax) })
		},

		/**
		 * A plain click on a route in the site stays in the site; the sign-in
		 * is a real navigation to the sign-in edge, after the choices are kept.
		 *
		 * @param {MouseEvent} event The click.
		 * @return {void}
		 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-4
		 */
		openAction(event) {
			this.keep()
			if (staysInSite(event, this.actionButton)) {
				event.preventDefault()
				this.$emit('navigate', this.actionButton.route)
			}
		},

		/**
		 * @param {MouseEvent} event The click.
		 * @return {void}
		 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-3
		 */
		openBack(event) {
			if (staysInSite(event, this.back)) {
				event.preventDefault()
				this.$emit('navigate', this.back.route)
			}
		},
	},
}
</script>

<style scoped>
.nl-public-detail {
	display: flex;
	flex-direction: column;
	gap: 1.5rem;
	max-inline-size: 51.25rem;
}

.nl-public-detail__facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 0.25rem 1.5rem;
	margin: 0;
}

.nl-public-detail__facts > div {
	display: contents;
}

.nl-public-detail__facts dt {
	font-weight: 700;
}

.nl-public-detail__facts dd {
	margin: 0;
}

.nl-public-detail__dates {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr));
	gap: 0.75rem;
	border: 0;
	padding: 0;
}

.nl-public-detail__dates legend {
	grid-column: 1 / -1;
}

.nl-public-detail__date {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	padding: 1rem;
	border: 1px solid var(--nldesign-color-border-dark, currentcolor);
	border-radius: var(--utrecht-border-radius-md, 0.75rem);
	cursor: pointer;
}

.nl-public-detail__date--selected {
	outline: 2px solid var(--nldesign-color-primary, currentcolor);
	background: var(--nldesign-color-primary-light, transparent);
}

.nl-public-detail__date-label {
	font-weight: 700;
}

.nl-public-detail__action {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	align-items: flex-start;
}

.nl-public-detail__count {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}
</style>
