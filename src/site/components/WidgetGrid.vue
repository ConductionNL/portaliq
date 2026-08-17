<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!--
		BANDS ARE NOT LAID OUT IN THE GRID, and that is the whole reason this
		template has two arms.

		A band paints edge to edge and brings its own `.container`. Putting one
		in a grid cell inside the page's content column clamps it: measured
		against the reference, a hero rendered that way came out 1168px wide
		against the design's 1280, and nothing inside the hero could recover the
		width because the limit was an ancestor.

		The reference's own structure is the model — `main` is full-bleed, every
		`section` carries a container — so bands are emitted as direct children
		here and only the remaining widgets get grid geometry, inside a
		container of their own.
	-->
	<div data-testid="widget-grid">
		<template v-for="(run, runIndex) in runs" :key="`run-${runIndex}`">
			<!-- A BAND: full bleed, brings its own container. -->
			<component
				:is="componentFor(run.widget.widgetKey)"
				v-if="run.band && componentFor(run.widget.widgetKey)"
				:data-testid="`widget-${run.widget.id || run.widget.widgetKey}`"
				:data-widget-key="run.widget.widgetKey"
				v-bind="propsFor(run.widget)" />

			<!-- A RUN of ordinary widgets: one grid, inside one container. The
			     container is here rather than around the whole component so a
			     band between two runs still reaches the viewport edge. -->
			<div v-else-if="!run.band" class="container">
				<div class="pq-grid">
					<div
						v-for="widget in run.widgets"
						:key="widget.id || `${widget.gridX}-${widget.gridY}`"
						class="pq-grid__cell"
						:data-testid="`widget-${widget.id || widget.widgetKey}`"
						:data-widget-key="widget.widgetKey"
						:style="cellStyle(widget, run.rowOffset)">
						<component
							:is="componentFor(widget.widgetKey)"
							v-if="componentFor(widget.widgetKey)"
							v-bind="propsFor(widget)"
							@navigate="$emit('navigate', $event)" />

						<!-- Anything not public, or not known, degrades to an inert
						     placeholder. It does NOT throw: a public page with one bad
						     widget must still show its other three, and a page that blanks
						     is a worse failure than a visibly missing tile. -->
						<p
							v-else
							class="pq-grid__placeholder"
							data-testid="widget-placeholder">
							{{ placeholderText(widget.widgetKey) }}
						</p>
					</div>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
import { siteBlockIsBand, siteBlockRegistry } from '@conduction/nextcloud-vue/public'
import ContributionsBlock from './ContributionsBlock.vue'
import HeroBlock from './HeroBlock.vue'
import MarkdownBlock from './MarkdownBlock.vue'
import { cellStyle, runsFor } from '../lib/gridPlacement.js'

/**
 * The widget keys this renderer will mount at a PUBLIC origin, mapped to the
 * component that renders each.
 *
 * ADR-084 §5: public exposure is opt-in and default-closed. It is an
 * allow-list, never a deny-list: a widget added to the shared catalog must not
 * become anonymously mountable simply by existing.
 *
 * THE MAP IS THE GATE. An earlier version of this component kept a separate
 * `Set` of allowed keys and then rendered on a hard-coded
 * `widgetKey === 'markdown'` check. The allow-list therefore decided nothing:
 * a mutation test that added `files` to it still passed, because the second
 * condition kept the placeholder in place. The gate looked like it was there
 * and was not. Keeping the decision and the rendering in ONE structure is what
 * makes it real — and what makes a mutation of it observable.
 *
 * CHAIN LINK 2 HAS LANDED. This file used to say the shared registry carried no
 * `public` flag, so a one-key map stood in for it. `@conduction/nextcloud-vue/public`
 * is that flag, expressed as a separate ENTRY POINT rather than a boolean:
 * everything reachable from it is verified — by a transitive import walk in the
 * library's own CI — to pull no `@nextcloud/*` runtime, which is precisely what
 * "safe to mount at a public origin" means here.
 *
 * That distinction is not academic. A DIRECT import check on the library's
 * existing widgets said 12 of 13 were clean; following relative imports through
 * the tree showed 12 of 13 reach `@nextcloud/l10n`, and one also reaches
 * `@nextcloud/vue`, `@nextcloud/auth` and `@nextcloud/event-bus`. A boolean
 * flag on the shared catalog would have been set from the first check.
 *
 * `markdown` stays owned here: it renders untrusted authored content and its
 * sanitisation posture is this app's decision, not a library default.
 *
 * `contributions` stays owned here for the same class of reason: a
 * contribution is portaliq's own contract (ADR-046), described by this app's
 * provider protocol rather than by the design system.
 *
 * `hero` is the library's band WITH calls to action, and it is listed AFTER the
 * spread on purpose — the spread would otherwise put the library's version
 * back. See `HeroBlock.vue` for why the actions live here rather than upstream.
 * The key is unchanged, so `siteBlockIsBand('hero')` still answers yes and the
 * band still paints edge to edge.
 */
const PUBLIC_WIDGETS = {
	markdown: MarkdownBlock,
	contributions: ContributionsBlock,
	...siteBlockRegistry,
	hero: HeroBlock,
}

export default {
	name: 'WidgetGrid',

	components: { ContributionsBlock, HeroBlock, MarkdownBlock },

	props: {
		/** Widget placements in the canonical manifest-v2 widgetEntry shape. */
		widgets: {
			type: Array,
			default: () => [],
		},

		/**
		 * The portal's glossary rows, for a page that places a `glossary`
		 * block. Fetched once by the host over the public contract; see
		 * `propsFor()` for why the block does not fetch its own.
		 */
		glossary: {
			type: Array,
			default: () => [],
		},

		/**
		 * The portal's contributed surfaces, for a page that places a
		 * `contributions` block. Supplied by the host for the same reason the
		 * glossary rows are; see `propsFor()`.
		 */
		contributions: {
			type: Array,
			default: () => [],
		},
	},

	// A block deep in the grid may ask to navigate; the router lives in App.vue.
	emits: ['navigate'],

	computed: {
		/**
		 * The widget list split into alternating BANDS and RUNS.
		 *
		 * A band is emitted on its own so it can paint edge to edge; the
		 * widgets between bands are grouped into one grid inside one
		 * `.container`. Grouping matters: wrapping each cell in its own
		 * container would put every widget in a separate grid and destroy the
		 * 12-column placement they were authored against.
		 *
		 * Order is preserved exactly as authored — a band does not float to the
		 * top, it splits the page where the author put it.
		 *
		 * EACH RUN CARRIES A `rowOffset`, and without it splitting the list
		 * leaves HOLES. `gridY` is absolute over the whole page, so a run whose
		 * first widget sits at row 4 opened its own grid with four empty rows
		 * above it — rows reserved for a band that is not in this grid at all.
		 * Measured on the La Franken landing page as a 320px void between the
		 * hero and the first line of text. The offset re-bases every run on its
		 * own first row; relative placement within the run is untouched, so the
		 * 12-column geometry an author laid out still holds.
		 *
		 * @return {Array} Alternating `{band: true, widget}` / `{band: false, widgets, rowOffset}` entries.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		runs() {
			return runsFor(this.widgets, (key) => this.isBand(key))
		},
	},

	methods: {
		/**
		 * Whether a block is a full-bleed band that owns its own container.
		 *
		 * Answered by the LIBRARY rather than by a list here, so a block added
		 * upstream arrives with its layout contract instead of needing this app
		 * to learn about it separately.
		 *
		 * @param {string} key The registry key.
		 * @return {boolean} True when it must not be wrapped in a grid cell.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		isBand(key) {
			return siteBlockIsBand(key)
		},

		/**
		 * The component that renders a widget key at a public origin.
		 *
		 * @param {string} key The registry key.
		 * @return {object|null} The component, or null when the key is not public.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-only-explicitly-public-widgets-must-render-at-a-public-origin
		 */
		componentFor(key) {
			return Object.hasOwn(PUBLIC_WIDGETS, key) ? PUBLIC_WIDGETS[key] : null
		},

		/**
		 * The props handed to a mounted widget.
		 *
		 * Only the placement's declared `props` are passed through, never the
		 * placement itself: a widget has no business reading its own grid
		 * coordinates, and forwarding the whole entry would hand it whatever
		 * else an author happened to put there.
		 *
		 * @param {object} widget The placement.
		 * @return {object} The component props.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-only-explicitly-public-widgets-must-render-at-a-public-origin
		 */
		propsFor(widget) {
			const props = widget.props || {}

			if (widget.widgetKey === 'markdown') {
				return { source: props.markdown || '' }
			}

			// THE GLOSSARY'S ROWS ARE DATA, NOT PAGE CONFIGURATION.
			//
			// Every other block is fully described by what its author typed.
			// This one is not: the terms live in the portal's own store and
			// this app already fetched them over the public contract. So the
			// host supplies the rows and the author supplies the wording — a
			// block that fetched for itself could not render at a public
			// origin, which is the entire premise of that entry point.
			//
			// Authored props win, so a page can override any label; `terms`
			// comes first so a page that names none still gets them.
			if (widget.widgetKey === 'glossary') {
				return { terms: this.glossary, ...props }
			}

			// SAME RULE, SECOND SUBJECT. The contributed surfaces are data the
			// host fetched over the public contract (ADR-046), not page
			// configuration, so the host supplies the rows and the author
			// supplies the wording.
			if (widget.widgetKey === 'contributions') {
				return { contributions: this.contributions, ...props }
			}

			return props
		},

		/**
		 * Placeholder text for a widget that will not be mounted.
		 *
		 * @param {string} key The registry key.
		 * @return {string} The placeholder text.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-only-explicitly-public-widgets-must-render-at-a-public-origin
		 */
		placeholderText(key) {
			return `Widget "${key}" is niet beschikbaar op een openbare pagina.`
		},

		/**
		 * Place one widget on the 12-column grid.
		 *
		 * Delegates to `lib/gridPlacement.js` — see there for the clamping and
		 * the row re-basing, and `tests/site-grid.spec.mjs` for what pins them.
		 *
		 * @param {object} widget    The placement.
		 * @param {number} rowOffset The run's first authored row.
		 * @return {object} The style bindings.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		cellStyle(widget, rowOffset = 0) {
			return cellStyle(widget, rowOffset)
		},
	},
}
</script>

<style scoped>
.pq-grid {
	display: grid;
	grid-template-columns: repeat(12, 1fr);
	/* Matches the shared dashboard grid's row unit so a page authored against
	   that geometry is the same height here. */
	grid-auto-rows: minmax(20px, auto);
	gap: 8px;
}

.pq-grid__cell {
	min-width: 0;
}

.pq-grid__placeholder {
	color: var(--pq-muted-color, #6b6b6b);
	font-style: italic;
	margin: 0;
}

@media (max-width: 768px) {
	/* Below the manifest grid's narrow breakpoint every cell is full width;
	   a 12-column layout on a phone is unreadable, and this is a public,
	   mobile-visited surface. */
	.pq-grid {
		display: block;
	}

	.pq-grid__cell {
		margin-bottom: 1rem;
	}
}
</style>
