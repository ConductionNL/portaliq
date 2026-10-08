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
	<CnSiteSection variant="hero" :backgroundImage="backgroundImage">
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

		<div v-if="search" class="ac-card ac-card--blue ac-card--padding-lg">
			<div class="ac-card__content">
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
	</CnSiteSection>
</template>

<script>
import {
	CnSiteIcon,
	CnSiteSearch,
	CnSiteSection,
} from '@conduction/nextcloud-vue/public'
import { heroActions } from '../lib/blockProps.js'

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
	},

	emits: ['search', 'navigate'],

	computed: {
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
		 * @spec openspec/changes/site-hero-and-notice-band/specs/site-look/spec.md#requirement-a-hero-must-show-its-heading-lead-and-search-box-together
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
		 * @spec openspec/changes/site-hero-and-notice-band/specs/site-look/spec.md#requirement-a-hero-must-show-its-heading-lead-and-search-box-together
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
		 * @spec openspec/changes/site-hero-and-notice-band/specs/site-look/spec.md#requirement-a-hero-must-show-its-heading-lead-and-search-box-together
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
