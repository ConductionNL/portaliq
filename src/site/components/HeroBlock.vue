<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<CnSiteHero
		:title="title"
		:subtitle="subtitle"
		:headingLevel="headingLevel"
		:headingVisible="headingVisible"
		:search="search"
		:searchLabel="searchLabel"
		:searchPlaceholder="searchPlaceholder"
		:searchSubmitLabel="searchSubmitLabel"
		:backgroundImage="backgroundImage"
		@search="$emit('search', $event)">
		<!--
			THE CALLS TO ACTION LIVE IN THE HERO'S SLOT.

			They are anchors, not buttons: every one of them navigates. A
			`<button>` that changes the page is a control a keyboard user
			cannot open in a new tab and a screen reader announces as the wrong
			kind of thing.

			`variant` decides which of the two token sets paints it, and
			anything other than `primary` gets the secondary treatment — an
			unknown variant must not fall through to no styling at all.
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
	</CnSiteHero>
</template>

<script>
import { CnSiteHero } from '@conduction/nextcloud-vue/public'

/**
 * The hero band, plus the calls to action the shared component has no prop for.
 *
 * WHY THIS EXISTS IN THIS APP RATHER THAN IN THE LIBRARY.
 *
 * `CnSiteHero` renders a title, an optional subtitle and an optional search
 * box, and exposes a default slot. It has no `actions` prop. The reference this
 * portal is measured against leads with two buttons under the lead paragraph —
 * measured 179x51 and 155x51, orange and white — so without them the band is a
 * headline and nothing else.
 *
 * The prop belongs in the library eventually. It is here for now because the
 * library arrives through npm: adding it upstream reaches this app only after a
 * release and a version bump, and patching the installed copy would make this
 * deployment render something a clean `npm ci` does not — a build nobody else
 * can reproduce, which is a worse outcome than a local component.
 *
 * Nothing here is Conduction-specific. Every value is a token with a default
 * that renders a plain link, so a portal naming no button tokens gets text.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
 */
export default {
	name: 'HeroBlock',

	components: { CnSiteHero },

	props: {
		/** The hero heading. */
		title: {
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

		/** Whether the heading is painted rather than only present. */
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
	},

	emits: ['search'],

	computed: {
		/**
		 * The actions that can actually be rendered, normalised.
		 *
		 * An action with no label or no destination is DROPPED rather than
		 * rendered empty: a page body is authored data, and a button with no
		 * text is a control a visitor can focus, press, and learn nothing
		 * from. Dropping it leaves the band correct with one fewer button.
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
					// Anything not starting at this origin's root opens away from
					// the portal, and `noopener` is not optional on those.
					external: /^https?:\/\//.test(String(a.href)),
				}))
		},
	},
}
</script>
