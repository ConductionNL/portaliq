<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<CnSiteSection variant="hero" :backgroundImage="backgroundImage">
		<div class="pq-hero__layout">
			<div class="pq-hero__content">
				<!--
					THE ICON IS INSIDE THE HEADING, which is why this block
					renders the heading itself instead of delegating to
					`CnSiteHero`.

					Measured on the reference: `h1` is a flex row of an 80x92
					orange hexagon tile and the title text, 22px apart. Nothing
					outside the heading can put an element in it, and a
					pseudo-element cannot carry an author-chosen glyph.

					The icon is `aria-hidden` by way of `CnSiteIcon` and adds no
					text, so the accessible name of the heading is still exactly
					the title.
				-->
				<component :is="headingTag" v-if="title" :class="headingClass">
					<span v-if="titleIcon" class="pq-hero__title-icon">
						<CnSiteIcon :name="titleIcon" :size="38" />
					</span>
					<span class="pq-hero__title-text">{{ title }}</span>
				</component>

				<p v-if="subtitle" :class="subtitleClass">{{ subtitle }}</p>

				<!--
					THE CARD IS WHAT SIZES THE SEARCH BOX — copied from
					`CnSiteHero`, whose own comment records why: `nlds-app.css`
					says `.ac-hero .ac-card { inline-size: min(100%, 744px) }`,
					and without the card the form simply fills the column.
				-->
				<div v-if="search" class="ac-card ac-card--blue ac-card--padding-lg">
					<div class="ac-card__content">
						<CnSiteSearch
							:label="searchLabel || title"
							:labelVisible="true"
							:placeholder="searchPlaceholder"
							:submitLabel="searchSubmitLabel"
							@search="$emit('search', $event)" />
					</div>
				</div>

				<!--
					The actions are anchors, not buttons: every one of them
					navigates. A `<button>` that changes the page is a control a
					keyboard user cannot open in a new tab and a screen reader
					announces as the wrong kind of thing.
				-->
				<div v-if="renderableActions.length" class="pq-hero__actions">
					<a
						v-for="action in renderableActions"
						:key="action.href + action.label"
						class="pq-hero__action"
						:class="`pq-hero__action--${action.variant}`"
						:href="action.href"
						:rel="action.external ? 'noopener noreferrer' : null"
						:target="action.external ? '_blank' : null">
						{{ action.label }}
					</a>
				</div>
			</div>

			<!--
				DECORATION, AND MARKED AS SUCH.

				The honeycomb carries no information a visitor needs, so it is
				`aria-hidden` and contributes nothing to the page's text. A
				screen reader reading out six icon names and a stray letter
				would be strictly worse than silence.
			-->
			<div
				v-if="honeycomb.length"
				class="pq-hero__illustration"
				aria-hidden="true">
				<div class="pq-hero__honeycomb">
					<span
						v-for="(cell, i) in honeycomb"
						:key="i"
						class="pq-hero__cell"
						:class="{ 'pq-hero__cell--accent': cell.accent }">
						<CnSiteIcon v-if="cell.icon" :name="cell.icon" :size="32" />
						<template v-else>{{ cell.label }}</template>
					</span>
				</div>
			</div>
		</div>
	</CnSiteSection>
</template>

<script>
import {
	CnSiteIcon,
	CnSiteSearch,
	CnSiteSection,
} from '@conduction/nextcloud-vue/public'

/**
 * The hero band: heading, lead, search, calls to action and an illustration.
 *
 * WHY THIS IS NOT `CnSiteHero` WITH EXTRA PROPS.
 *
 * It started as a wrapper around it, and a wrapper cannot reach two of the
 * three things the reference band actually contains. `CnSiteHero` renders
 * `{{ title }}` as the whole of its heading and exposes only a trailing slot,
 * so an icon INSIDE the `h1` and an illustration BESIDE the content are both
 * out of reach — slot content lands after the subtitle, not next to it.
 *
 * So this composes `CnSiteSection` the same way `CnSiteHero` does, and keeps
 * its contract: the same heading-level and heading-visibility semantics, and
 * the same search card markup, which is copied deliberately rather than
 * reinvented (see the comment at that markup for what the card is for).
 *
 * All of it belongs upstream eventually. It is here because the library arrives
 * through npm: a prop added to `@conduction/nextcloud-vue` reaches this app only
 * after a release and a version bump, and patching the installed copy would make
 * this deployment render something a clean `npm ci` cannot reproduce.
 *
 * NOTHING HERE IS CONDUCTION-SPECIFIC. Every colour, size and shape is a token
 * whose default is inert, and the illustration renders only for a page that asks
 * for one. A municipal portal that names none of them gets a plain band.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
 */
export default {
	name: 'HeroBlock',

	components: { CnSiteIcon, CnSiteSearch, CnSiteSection },

	props: {
		/** The hero heading. */
		title: {
			type: String,
			default: '',
		},

		/** An icon rendered as a tile inside the heading. */
		titleIcon: {
			type: String,
			default: '',
		},

		/** The lead paragraph under the heading. */
		subtitle: {
			type: String,
			default: '',
		},

		/** Heading level, so the page outline stays intact. */
		headingLevel: {
			type: Number,
			default: 1,
		},

		/**
		 * Whether the heading is PAINTED as well as present.
		 *
		 * `null` means "decide from the search box", which is `CnSiteHero`'s
		 * rule and the reason this is not a plain Boolean: with a search box the
		 * field's label carries the prompt, and painting the heading too printed
		 * the same sentence twice.
		 */
		headingVisible: {
			type: Boolean,
			default: null,
		},

		/** Whether the band carries a search box. */
		search: {
			type: Boolean,
			default: false,
		},

		/** The search field's label. */
		searchLabel: {
			type: String,
			default: '',
		},

		/** The search field's placeholder. */
		searchPlaceholder: {
			type: String,
			default: '',
		},

		/** The search button's label. */
		searchSubmitLabel: {
			type: String,
			default: 'Zoeken',
		},

		/** A background image for the band. */
		backgroundImage: {
			type: String,
			default: '',
		},

		/** The calls to action: `{label, href, variant, external}`. */
		actions: {
			type: Array,
			default: () => [],
		},

		/**
		 * A decorative illustration: `{icons: [...], label: 'C'}`.
		 *
		 * Six icons ring one accented centre cell carrying the label — the
		 * arrangement the reference uses. Fewer icons simply leave cells out;
		 * more are ignored, because a seventh has nowhere to go.
		 */
		illustration: {
			type: Object,
			default: null,
		},
	},

	emits: ['search'],

	computed: {
		/**
		 * @return {string} The heading tag for the configured level.
		 */
		headingTag() {
			const level = Math.min(6, Math.max(1, Number(this.headingLevel) || 1))
			return `h${level}`
		},

		/**
		 * Whether the heading is painted rather than only present.
		 *
		 * @return {boolean} True when it should be visible.
		 */
		showHeading() {
			if (this.headingVisible !== null) {
				return this.headingVisible
			}

			return this.search === false
		},

		/**
		 * @return {Array} Classes for the heading.
		 */
		headingClass() {
			return ['ac-hero__title', this.showHeading ? null : 'sr-only'].filter(
				Boolean,
			)
		},

		/**
		 * @return {Array} Classes for the lead.
		 */
		subtitleClass() {
			return ['ac-hero__subtitle', this.showHeading ? null : 'sr-only'].filter(
				Boolean,
			)
		},

		/**
		 * The actions that can actually be rendered, normalised.
		 *
		 * An action with no label or no destination is DROPPED rather than
		 * rendered empty: a page body is authored data, and a button with no
		 * text is a control a visitor can focus, press, and learn nothing from.
		 *
		 * @return {Array} Normalised actions.
		 */
		renderableActions() {
			if (Array.isArray(this.actions) === false) {
				return []
			}

			return this.actions
				.filter((a) => a && typeof a === 'object' && a.label && a.href)
				.map((a) => ({
					label: String(a.label),
					href: String(a.href),
					variant: a.variant === 'primary' ? 'primary' : 'secondary',
					// Anything leaving this origin opens in a new tab, and
					// `noopener` is not optional on those.
					external: /^https?:\/\//.test(String(a.href)),
				}))
		},

		/**
		 * The seven cells, centre last so it paints over its neighbours.
		 *
		 * @return {Array} The cells to render.
		 */
		honeycomb() {
			const config = this.illustration
			if (!config || typeof config !== 'object') {
				return []
			}

			const icons = Array.isArray(config.icons) ? config.icons.slice(0, 6) : []
			if (icons.length === 0 && !config.label) {
				return []
			}

			return [
				...icons.map((icon) => ({ icon: String(icon), accent: false })),
				{ label: String(config.label || ''), accent: true },
			]
		},
	},
}
</script>
