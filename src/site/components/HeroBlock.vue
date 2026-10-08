<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!--
		The `hero` band. It keeps `CnSiteHero`'s contract (same props, same
		heading and search card markup) and adds what that component cannot
		hold: an eyebrow above the heading, an icon inside it, and at most two
		calls to action (REQ-PTB-006).
	-->
	<CnSiteSection
		variant="hero"
		:backgroundImage="backgroundImage"
		:class="{ 'pq-hero--plain': plain, 'pq-hero--aside': hasAside }">
		<!-- THE MAIN COLUMN (hero-aside). Without an aside it lays out as no
		     box at all (`display: contents`), so the band reads as before;
		     with one it is the column beside it. -->
		<div class="pq-hero__main">
			<!-- A label for the heading, not a heading: one outline entry per hero. -->
			<p v-if="eyebrow" class="pq-hero__eyebrow" data-testid="hero-eyebrow">
				{{ eyebrow }}
			</p>

			<component :is="`h${headingLevel}`" v-if="title" :class="headingClass">
				<span v-if="titleIcon" class="pq-hero__title-icon">
					<CnSiteIcon :name="titleIcon" :size="38" />
				</span>
				{{ title }}
			</component>

			<p v-if="subtitle" :class="subtitleClass">
				{{ subtitle }}
			</p>

			<!-- The plain variant (Zuiddrecht board Home) draws the form on the
		     band itself, input and button joined, without the card. -->
			<div
				v-if="search"
				:class="
					plain
						? 'pq-hero__search'
						: 'ac-card ac-card--blue ac-card--padding-lg'
				">
				<div :class="{ 'ac-card__content': !plain }">
					<CnSiteSearch
						:label="searchFieldLabel"
						:labelVisible="searchLabelVisible"
						:placeholder="searchPlaceholder"
						:submitLabel="searchSubmitLabel"
						:value="searchValue"
						:inputId="searchInputId"
						@search="$emit('search', $event)" />
				</div>
			</div>

			<!-- "Veel gezocht": the pages a visitor most often searches for, as
		     plain links under the form. -->
			<p
				v-if="popular.length"
				class="pq-hero__popular"
				data-testid="hero-popular">
				<span v-if="popularLabel" class="pq-hero__popular-label">{{
					popularLabel
				}}</span>
				<a
					v-for="link in popular"
					:key="link.href"
					class="utrecht-link pq-hero__popular-link"
					:href="link.href"
					@click="onAction($event, link.href)"
					>{{ link.label }}</a
				>
			</p>

			<!-- Every action navigates, so each is a link, never a button. An
		     in-site route is emitted, like every other block's links. -->
			<div v-if="shownActions.length" class="pq-hero__actions">
				<a
					v-for="action in shownActions"
					:key="action.href + action.label"
					class="pq-hero__action"
					:href="action.href"
					data-testid="hero-action"
					@click="onAction($event, action.href)">
					{{ action.label }}
				</a>
			</div>
		</div>

		<!-- THE ASIDE (hero-aside): a list block in a card (the academy's
		     next course days), or a photo (Esdoornveen). -->
		<div
			v-if="asideWidget"
			class="pq-hero__aside pq-hero__aside-card"
			data-testid="hero-aside">
			<component
				:is="asideWidget"
				v-bind="asideProps"
				@navigate="$emit('navigate', $event)" />
		</div>
		<img
			v-else-if="asidePhoto && asidePhoto.src"
			class="pq-hero__aside pq-hero__photo"
			:src="asidePhoto.src"
			:alt="asidePhoto.alt"
			data-testid="hero-aside" />
		<p
			v-else-if="asidePhoto"
			class="pq-hero__aside pq-hero__photo pq-hero__photo--label"
			data-testid="hero-aside">
			{{ asidePhoto.label }}
		</p>
	</CnSiteSection>
</template>

<script>
import {
	CnSiteIcon,
	CnSiteSearch,
	CnSiteSection,
} from '@conduction/nextcloud-vue/public'
import { defineAsyncComponent } from 'vue'
import { heroActions, heroPopularLinks } from '../lib/blockProps.js'
import { loaders } from '../widgets/loaders.js'

/**
 * The blocks a hero may hold beside its text (hero-aside): lists that fit a
 * card, never another band.
 */
const ASIDE_WIDGETS = ['nlEventList', 'nlLinkList', 'nlNewsList']

/**
 * The hero band (`hero`), registered over the library's `CnSiteHero` under
 * the same key, so `siteBlockIsBand('hero')` still answers true.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-hero-must-cap-its-calls-to-action-and-keep-one-outline-entry-req-ptb-006
 */
export default {
	name: 'HeroBlock',

	components: { CnSiteIcon, CnSiteSearch, CnSiteSection },

	props: {
		/** The short line above the heading. A paragraph, never a heading. */
		eyebrow: { type: String, default: '' },
		/** The hero heading. */
		title: { type: String, default: '' },
		/** An icon inside the heading; decorative. */
		titleIcon: { type: String, default: '' },
		/** The lead under the heading. */
		subtitle: { type: String, default: '' },
		/** Heading level, so the page outline stays intact. */
		headingLevel: {
			type: Number,
			default: 1,
			validator: (v) => v >= 1 && v <= 6,
		},

		/** Whether to render the search box. */
		search: { type: Boolean, default: false },
		/** The search box's accessible name; the title when empty. */
		searchLabel: { type: String, default: '' },
		/** Placeholder inside the search field. */
		searchPlaceholder: { type: String, default: '' },
		/** Visible text on the search button. */
		searchSubmitLabel: { type: String, default: 'Zoeken' },
		/** Pre-filled search term. */
		searchValue: { type: String, default: '' },
		/** DOM id for the search input. */
		searchInputId: { type: String, default: 'cn-site-search' },
		/** Optional background image for the band. */
		backgroundImage: { type: String, default: '' },
		/** Paint the heading; `null` paints it, also beside a search box. */
		headingVisible: { type: Boolean, default: null },
		/** `{label, href}` calls to action; at most two render. */
		actions: { type: Array, default: () => [] },
		/** `card` (the search in a card, today's look) or `plain` (joined input and button on the band). */
		variant: { type: String, default: 'card' },
		/** The words before the popular links ("Veel gezocht:"). */
		popularLabel: { type: String, default: '' },
		/** `{label, href}` links under the form; at most six render. */
		popularLinks: { type: Array, default: () => [] },
		/**
		 * A block beside the text, in a card: `{widgetKey, props}`, one of
		 * the list blocks (hero-aside).
		 */
		aside: { type: Object, default: null },
		/**
		 * A photo beside the text: `{src, alt}`, or `{label}` for a design
		 * that marks where a photo goes (hero-aside).
		 */
		asideImage: { type: Object, default: null },
		/** The portal, handed in by the host, for the block beside the text. */
		portal: { type: String, default: '' },
	},

	emits: ['search', 'navigate'],

	computed: {
		/**
		 * The block beside the text, loaded on demand, or null.
		 *
		 * @return {object|null} An async component.
		 * @spec openspec/changes/hero-aside/specs/portaliq-cms/spec.md#requirement-a-hero-may-hold-a-list-or-a-photo-beside-its-text
		 */
		asideWidget() {
			const key = this.aside?.widgetKey
			if (!ASIDE_WIDGETS.includes(key) || typeof loaders[key] !== 'function') {
				return null
			}
			return defineAsyncComponent(loaders[key])
		},

		/**
		 * The props of the block beside the text, with the portal.
		 *
		 * @return {object} The props.
		 * @spec openspec/changes/hero-aside/specs/portaliq-cms/spec.md#requirement-a-hero-may-hold-a-list-or-a-photo-beside-its-text
		 * @spec openspec/changes/hero-on-the-school-boards/specs/portaliq-cms/spec.md#requirement-the-hero-hands-its-portal-to-the-block-beside-it-and-draws-its-search-on-the-band
		 */
		asideProps() {
			const props = this.aside?.props
			const own =
				props && typeof props === 'object' && !Array.isArray(props)
					? props
					: {}
			// The host's portal after the authored props, as the grid does
			// for the same blocks (hero-on-the-school-boards).
			return this.portal ? { ...own, portal: this.portal } : own
		},

		/**
		 * The photo beside the text: an address on this site or the web with
		 * its alternative text, else a label, else null.
		 *
		 * @return {{src: string, alt: string, label: string}|null} The photo.
		 * @spec openspec/changes/hero-aside/specs/portaliq-cms/spec.md#requirement-a-hero-may-hold-a-list-or-a-photo-beside-its-text
		 */
		asidePhoto() {
			const image = this.asideImage
			if (!image || typeof image !== 'object') {
				return null
			}
			const src = String(image.src || '').trim()
			const safe = /^(https:\/\/|\/(?!\/))/.test(src) ? src : ''
			const label = String(image.label || '').trim()
			if (safe === '' && label === '') {
				return null
			}
			return { src: safe, alt: String(image.alt || '').trim(), label }
		},

		/**
		 * Whether the band has something beside its text.
		 *
		 * @return {boolean} True with an aside.
		 * @spec openspec/changes/hero-aside/specs/portaliq-cms/spec.md#requirement-a-hero-may-hold-a-list-or-a-photo-beside-its-text
		 */
		hasAside() {
			return this.asideWidget !== null || this.asidePhoto !== null
		},

		/**
		 * The actions that render: labelled, with a destination, two at most.
		 *
		 * @return {Array} `{label, href}` entries.
		 *
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-hero-must-cap-its-calls-to-action-and-keep-one-outline-entry-req-ptb-006
		 */
		shownActions() {
			return heroActions(this.actions)
		},

		/**
		 * @return {boolean} Whether the band draws the plain variant.
		 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-the-hero-may-draw-its-search-plain-with-popular-links
		 */
		plain() {
			return this.variant === 'plain'
		},

		/**
		 * The popular links that render: labelled, in-site or on the web, six at most.
		 *
		 * @return {Array<{label: string, href: string}>} The links.
		 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-the-hero-may-draw-its-search-plain-with-popular-links
		 */
		popular() {
			return heroPopularLinks(this.popularLinks)
		},

		/**
		 * Whether the heading is painted.
		 *
		 * `CnSiteHero`'s rule hid the heading and the lead whenever the band
		 * held a search box, because the box was labelled with the heading. The
		 * school and municipality designs draw heading, lead and search box
		 * together, so the heading shows unless an author turns it off.
		 *
		 * @return {boolean} True when visible.
		 *
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-hero-must-cap-its-calls-to-action-and-keep-one-outline-entry-req-ptb-006
		 * @spec openspec/changes/site-hero-shows-its-heading/specs/site-look/spec.md#requirement-a-hero-must-show-its-heading-lead-and-search-box-together
		 */
		showHeading() {
			return this.headingVisible !== null ? this.headingVisible : true
		},

		/**
		 * The search box's name. The author's label; else, with the heading
		 * hidden, the heading (the old rule, so the band keeps one name); else
		 * the button's word, so a visible heading is not read out twice.
		 *
		 * @return {string} The label.
		 *
		 * @spec openspec/changes/site-hero-shows-its-heading/specs/site-look/spec.md#requirement-a-hero-must-show-its-heading-lead-and-search-box-together
		 */
		searchFieldLabel() {
			if (this.searchLabel !== '') {
				return this.searchLabel
			}

			if (this.showHeading === false && this.title !== '') {
				return this.title
			}

			return this.searchSubmitLabel || 'Zoeken'
		},

		/**
		 * Whether the label shows above the box: when the author wrote one, or
		 * when it stands in for a hidden heading. A label that only repeats
		 * the button's word is for screen readers.
		 *
		 * @return {boolean} True when visible.
		 *
		 * @spec openspec/changes/site-hero-shows-its-heading/specs/site-look/spec.md#requirement-a-hero-must-show-its-heading-lead-and-search-box-together
		 */
		searchLabelVisible() {
			return this.searchLabel !== '' || this.showHeading === false
		},

		/**
		 * @return {Array} Classes for the heading.
		 *
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-hero-must-cap-its-calls-to-action-and-keep-one-outline-entry-req-ptb-006
		 */
		headingClass() {
			return ['ac-hero__title', this.showHeading ? null : 'sr-only'].filter(
				Boolean,
			)
		},

		/**
		 * @return {Array} Classes for the lead.
		 *
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-hero-must-cap-its-calls-to-action-and-keep-one-outline-entry-req-ptb-006
		 */
		subtitleClass() {
			return ['ac-hero__subtitle', this.showHeading ? null : 'sr-only'].filter(
				Boolean,
			)
		},
	},

	methods: {
		/**
		 * Follow an in-site route without leaving the document.
		 *
		 * @param {MouseEvent} event The click.
		 * @param {string}     href  The action's destination.
		 * @return {void}
		 *
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-blocks-must-take-their-data-as-props-and-nothing-else-req-ptb-007
		 */
		onAction(event, href) {
			if (/^\/(?!\/)/.test(href) === false) {
				return
			}

			event.preventDefault()
			this.$emit('navigate', href)
		},
	},
}
</script>
