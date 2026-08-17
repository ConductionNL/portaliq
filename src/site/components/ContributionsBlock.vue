<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!--
		THE ROOT RENDERS WHENEVER THE BLOCK IS PLACED, EMPTY OR NOT.

		The markup this replaces was `v-if="contributions.length"` on the
		section itself, which is right for something the renderer emits on its
		own initiative and wrong for something an author placed: a page that
		asks for this block and gets nothing back should say so, not silently
		close the gap. A block that vanishes is indistinguishable from a block
		that was never authored, and that is precisely the confusion that let
		the contributed surfaces disappear from the portal unnoticed in the
		first place.
	-->
	<div class="pq-contributions" data-testid="site-contributions">
		<component :is="headingTag" v-if="title" :class="headingClass">
			{{ title }}
		</component>

		<p v-if="description" class="utrecht-paragraph">
			{{ description }}
		</p>

		<div
			v-for="contribution in contributions"
			:key="contribution.app"
			class="pq-contributions__app"
			:data-testid="`contribution-${contribution.app}`"
			:data-app="contribution.app">
			<component :is="entryHeadingTag" :class="entryHeadingClass">
				{{ contribution.label || contribution.app }}
			</component>

			<!--
				AN INDEX, NOT LIVE DATA (ADR-046).

				Each entry NAMES a collection or an action a leaf app publishes
				on this portal; following it is what fetches anything. That
				distinction is the whole safety story: this block is built from
				a publicly cacheable, anonymous response, so it must never
				itself carry a visitor's rows.
			-->
			<!--
				THE ENTRIES ARE LINKS NOW.

				They were plain text while contributed pages had no site route:
				a control that goes nowhere is worse than words. The route
				exists, so the false affordance is gone — and an entry whose app
				publishes no page for it STAYS text, because the alternative is
				a link to a 404.
			-->
			<ul class="pq-contributions__entries">
				<li
					v-for="entry in entriesOf(contribution)"
					:key="entry.id || entry.label"
					class="pq-contributions__entry"
					data-testid="contribution-entry">
					<a
						v-if="routeFor(contribution, entry)"
						:href="routeFor(contribution, entry)"
						@click.prevent="
							$emit('navigate', routeFor(contribution, entry))
						">
						{{ entry.label || entry.id }}
					</a>
					<template v-else>{{ entry.label || entry.id }}</template>
				</li>
			</ul>
		</div>

		<p
			v-if="!contributions.length"
			class="utrecht-paragraph pq-contributions__empty">
			{{ emptyLabel }}
		</p>
	</div>
</template>

<script>
import { contributionRoute } from '../lib/contributionApi.js'

/**
 * The contributed surfaces a portal offers — the "what can I do here" index.
 *
 * WHY THIS IS A BLOCK AND NOT A SECTION THE RENDERER EMITS
 *
 * It used to be a hard-coded `<section>` in `App.vue` carrying a literal
 * `<h2>Diensten</h2>`, rendered under the content of every page that happened
 * to satisfy `contributions.length`. A municipality could not move it, rename
 * it, translate it, reorder it or leave it out, and "Diensten / Meldingen"
 * turned up beneath pages whose author never asked for it. As a block it is
 * content an author places — which is what it always was.
 *
 * That conversion is also how this surface went missing: the section came out
 * of `App.vue` and nothing put a block back, so the public contract kept
 * answering with contributions that no page rendered. A bridge built from one
 * side is not a bridge, and it fails silently — the page looks complete.
 *
 * WHY THE ROWS ARE A PROP
 *
 * Identical to the glossary's reason (see `CnSiteGlossary`): this renderer
 * runs at a public origin where there is no `OC` global, no session and no
 * translation bundle, so a block that fetched for itself could not mount
 * there. The host already holds these rows — it fetched them over the same
 * public contract — so it hands them down, and every visible string is a prop
 * because "Diensten" is not a word this component is entitled to choose on
 * behalf of a Dutch government portal.
 *
 * WHY IT LIVES HERE AND NOT IN `@conduction/nextcloud-vue/public`
 *
 * The shared library owns block types every portal renderer can use. A
 * contribution is portaliq's own contract (ADR-046) — its shape is defined by
 * this app's provider protocol, not by the design system — so the block that
 * renders it belongs with the contract, exactly as `markdown` stays here
 * because its sanitisation posture is this app's decision.
 */
export default {
	name: 'ContributionsBlock',

	props: {
		/**
		 * The contributions for the serving portal, as the public contract
		 * returns them: `{ app, label?, collections?, actions? }`.
		 */
		contributions: {
			type: Array,
			default: () => [],
		},

		/** Heading above the index; '' renders none. */
		title: {
			type: String,
			default: '',
		},

		/** Supporting line under the heading. */
		description: {
			type: String,
			default: '',
		},

		/**
		 * Heading level, so the page outline stays intact.
		 *
		 * The design system styles `.utrecht-heading-2`, not `h2`, so the class
		 * tracks the level too — a host changing the level to keep an outline
		 * intact must not silently lose the styling with it.
		 */
		headingLevel: {
			type: Number,
			default: 2,
			validator: (v) => v >= 1 && v <= 5,
		},

		/**
		 * What to say when the portal offers no contributed surfaces.
		 *
		 * An empty index renders this sentence rather than a bare heading over
		 * nothing, which reads as a page that failed to load.
		 */
		emptyLabel: {
			type: String,
			default: '',
		},
	},

	emits: ['navigate'],

	computed: {
		/**
		 * @return {string} The heading element to render.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		headingTag() {
			return `h${this.headingLevel}`
		},

		/**
		 * @return {string} The heading's class, tracking its level.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		headingClass() {
			return `utrecht-heading-${this.headingLevel}`
		},

		/**
		 * @return {string} The per-app heading element, one level down.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		entryHeadingTag() {
			return `h${this.headingLevel + 1}`
		},

		/**
		 * @return {string} The per-app heading's class.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		entryHeadingClass() {
			return `utrecht-heading-${this.headingLevel + 1}`
		},
	},

	methods: {
		/**
		 * The entries of one contribution, as an index of what it offers.
		 *
		 * Collections and actions are shown together on purpose. To a visitor
		 * "my invoices" and "submit a declaration" are two things the portal
		 * can do; the split between reading a collection and invoking an
		 * action is an implementation detail of the contract, not a category a
		 * citizen recognises.
		 *
		 * @param {object} contribution One contribution.
		 * @return {Array} Its collections and actions.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-contribution-must-be-scoped-to-the-portal-it-targets
		 */
		/**
		 * The in-site route for an entry, when its app publishes a page for it.
		 *
		 * An entry is an action or a collection; a PAGE is what a visitor opens.
		 * They are matched by id, or by a page whose blocks invoke that action.
		 * No match returns null so the caller renders text rather than a dead link.
		 *
		 * @param {object} contribution The contributing app's record.
		 * @param {object} entry        The action or collection.
		 * @return {string|null} The route, or null.
		 */
		routeFor(contribution, entry) {
			const pages = Array.isArray(contribution.pages) ? contribution.pages : []
			const id = entry.id || ''
			const page = pages.find(
				(pg) =>
					pg
					&& (pg.id === id
						|| (Array.isArray(pg.blocks)
							&& pg.blocks.some((b) => b && b.action === id))),
			)
			if (!page) {
				return null
			}

			return contributionRoute(contribution.app, page.id)
		},

		entriesOf(contribution) {
			const collections = Array.isArray(contribution.collections)
				? contribution.collections
				: []
			const actions = Array.isArray(contribution.actions)
				? contribution.actions
				: []

			return [...collections, ...actions]
		},
	},
}
</script>

<style scoped>
/*
 * Layout and rhythm only — no colours.
 *
 * A block lands on whatever surface a host puts it on, and this codebase has
 * defects on record from rules that coloured text without reference to the
 * background behind it. The design system colours `.utrecht-heading-*` and
 * `.utrecht-paragraph` already.
 */
.pq-contributions__app + .pq-contributions__app {
	margin-block-start: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-contributions__entries {
	margin-block-start: var(--utrecht-space-block-sm, 0.5rem);
	padding-inline-start: 1.25rem;
}

.pq-contributions__entry + .pq-contributions__entry {
	margin-block-start: var(--utrecht-space-block-xs, 0.25rem);
}
</style>
