<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The page for a route that does not exist or is not published, drawn on the
	Zuiddrecht board NietGevonden (contact-page-question-form-and-not-found).

	An unpublished page and a route that never existed get THIS page and
	nothing else: the answer carries no word that tells them apart. The
	`data-portaliq-status` and `data-portaliq-path` attributes stay on the
	wrapper because the traffic collector counts missing pages by them.
-->
<template>
	<div
		class="container pq-not-found"
		role="alert"
		data-testid="site-error"
		data-portaliq-status="404"
		:data-portaliq-path="path">
		<p class="utrecht-paragraph pq-not-found__code" data-testid="not-found-code">
			{{ tr('Error code 404') }}
		</p>
		<h1 class="utrecht-heading-1">
			{{ t('Page not found') }}
		</h1>
		<p class="utrecht-paragraph pq-not-found__intro">
			{{
				tr(
					'This page does not exist (any more). Maybe the address was typed wrong, or we moved the page.',
				)
			}}
		</p>

		<form
			v-if="view.search"
			class="pq-not-found__search"
			role="search"
			data-testid="not-found-search"
			@submit.prevent="onSearch">
			<label class="utrecht-form-label" for="pq-not-found-term">{{
				tr('Search for what you need')
			}}</label>
			<!-- The plain search's field: input and button joined. -->
			<div class="ac-search-box__search">
				<input
					id="pq-not-found-term"
					v-model="term"
					class="ac-search-box__input utrecht-textbox utrecht-textbox--html-input"
					type="search"
					name="q"
					autocomplete="off" />
				<button
					type="submit"
					class="ac-search-box__button utrecht-button utrecht-button--primary-action">
					{{ t('Search') }}
				</button>
			</div>
		</form>

		<h2 class="utrecht-heading-3 pq-not-found__more">
			{{ tr('Or go on to') }}
		</h2>
		<ul class="pq-not-found__links" data-testid="not-found-links">
			<li v-for="link in view.links" :key="link.kind">
				<a
					:href="hrefFor(link.route)"
					class="utrecht-link pq-not-found__link"
					:data-testid="`not-found-${link.kind}`"
					@click.prevent="$emit('navigate', link.route)">
					<svg
						class="pq-not-found__chevron"
						viewBox="0 0 24 24"
						aria-hidden="true"
						focusable="false">
						<path d="M9 5l7 7-7 7" />
					</svg>
					<span>{{ link.label }}</span>
				</a>
			</li>
		</ul>

		<p
			v-if="view.report"
			class="utrecht-paragraph pq-not-found__report"
			data-testid="not-found-report">
			{{ view.report }}
		</p>
	</div>
</template>

<script>
import { fetchPages } from '../lib/contentApi.js'
import { contactRouteOf } from '../lib/notFound.js'
import { notFoundView } from '../lib/notFoundView.js'
import { pageLocale } from '../pages/inbox/translate.js'

import '@utrecht/button-css/dist/index.css'
import '@utrecht/link-css/dist/index.css'
import '@utrecht/form-label-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'
import '@utrecht/textbox-css/dist/index.css'

const WORDS = {
	nl: {
		'Error code 404': 'Foutcode 404',
		'This page does not exist (any more). Maybe the address was typed wrong, or we moved the page.':
			'Deze pagina bestaat niet (meer). Misschien is het adres verkeerd getypt, of hebben wij de pagina verplaatst.',
		'Search for what you need': 'Zoek wat u nodig hebt',
		'Or go on to': 'Of ga verder naar',
		'The homepage': 'De homepage',
		Contact: 'Contact',
		'Did you get here through a link on our website? Let us know through Contact, and we will repair the link.':
			'Kwam u hier via een link op onze website? Laat het ons weten via Contact, dan herstellen wij de link.',
	},
	en: {},
}

/**
 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t04
 */
export default {
	name: 'NotFoundPage',

	props: {
		/** The route that was asked for, kept for the traffic collector. */
		path: { type: String, default: '' },
		/** The public site record: its contact route and the name of its resident area. */
		site: { type: Object, default: () => ({}) },
		/** The portal's slug, for reading its published pages. */
		portalSlug: { type: String, default: '' },
		/** The language the visitor chose. */
		locale: { type: String, default: '' },
		/** Whether the portal offers a way to sign in. */
		hasWaysIn: { type: Boolean, default: false },
		/** The published pages, already read (test seam). */
		initialPages: { type: Array, default: null },
		/** Whether the portal has search. */
		searchEnabled: { type: Boolean, default: false },
		/** The address of an in-site route, so a link opens in a new tab. */
		hrefForRoute: { type: Function, default: (route) => route },
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
	},

	emits: ['navigate', 'search'],

	data() {
		return { term: '', pages: this.initialPages }
	},

	computed: {
		/**
		 * The page's own words, with the shell's translator for the rest. They
		 * live here, not in the shared catalogue, which the first-load entry carries.
		 *
		 * @return {(key: string) => string} The translator.
		 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t04
		 */
		tr() {
			const own = WORDS[pageLocale(this.locale)] || {}
			return (key) => own[key] ?? this.t(key)
		},

		/**
		 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t04
		 */
		view() {
			return notFoundView({
				contactRoute: contactRouteOf(this.site),
				pages: this.pages,
				hasResidentArea:
					String(this.site.accountLabel || '') !== '' || this.hasWaysIn,
				residentLabel: String(this.site.accountLabel || ''),
				searchEnabled: this.searchEnabled,
				t: this.tr,
			})
		},
	},

	/**
	 * Read the portal's pages when none were handed in.
	 *
	 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t05
	 */
	mounted() {
		if (this.initialPages === null) {
			this.loadPages()
		}
	},

	methods: {
		/**
		 * Read the published pages once. A failed read leaves them unknown,
		 * which hides the contact link: the page never points at a route it
		 * could not confirm.
		 *
		 * @return {Promise<void>} Resolves when read.
		 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t04
		 */
		async loadPages() {
			try {
				this.pages = await fetchPages(
					this.portalSlug || undefined,
					this.locale || undefined,
				)
			} catch {
				this.pages = null
			}
		},

		/**
		 * @param {string} route An in-site route.
		 * @return {string} Its address.
		 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t04
		 */
		hrefFor(route) {
			return this.hrefForRoute(route)
		},

		/**
		 * Hand a typed term to the shell, which opens the search page.
		 *
		 * @return {void}
		 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t04
		 */
		onSearch() {
			const term = this.term.trim()
			if (term !== '') {
				this.$emit('search', term)
			}
		},
	},
}
</script>

<style scoped>
/* Board NietGevonden: one column of at most 760px, its parts 24px apart. */
.pq-not-found {
	display: flex;
	flex-direction: column;
	gap: 1.5rem;
	padding-block: 1.5rem 1rem;
}

.pq-not-found > * {
	max-inline-size: 47.5rem;
	margin: 0;
}

.pq-not-found__code.utrecht-paragraph {
	color: var(
		--nldesign-color-text-muted,
		var(--utrecht-document-color, CanvasText)
	);
	font-size: 1.125rem;
	font-weight: 600;
}

.pq-not-found__intro.utrecht-paragraph {
	font-size: 1.3125rem;
	line-height: 1.55;
}

.pq-not-found__search {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.pq-not-found__search .utrecht-form-label {
	font-size: 1.0625rem;
	font-weight: 600;
}

/* The field takes the column's width, as on the board. */
.pq-not-found__search .utrecht-textbox {
	max-inline-size: none;
}

.pq-not-found__more {
	margin-block-start: 0.5rem;
}

.pq-not-found__links {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	padding: 0;
	list-style: none;
	font-size: 1.1875rem;
}

.pq-not-found__link {
	display: inline-flex;
	align-items: center;
	gap: 0.5rem;
	min-block-size: 2.75rem;
	font-weight: 600;
}

.pq-not-found__chevron {
	flex: none;
	inline-size: 1.25rem;
	block-size: 1.25rem;
	fill: none;
	stroke: currentcolor;
	stroke-width: 2;
}

.pq-not-found__report.utrecht-paragraph {
	color: var(
		--nldesign-color-text-muted,
		var(--utrecht-document-color, CanvasText)
	);
	font-size: 1rem;
	line-height: 1.5;
}
</style>
