<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div
		class="pq-site ac-app-container pq-canvas"
		:class="themeClass"
		:style="{ inlineSize: width + 'px' }"
		data-testid="editor-canvas"
		@click.capture="onCanvasClick">
		<!--
			THE REAL BLOCKS, NOT A PREVIEW OF THEM (task 4.1).

			This mounts `BrandHeader`, `WidgetGrid` and `FooterColumns` — the
			same components the public route mounts, inside the same
			`.pq-site.ac-app-container` wrapper, under the same stylesheets,
			with the portal's own theme class. An editor that draws its own
			approximation of a block is an editor that lies: the author tunes
			something that is not what ships, and the difference only appears
			after publishing.

			`tests/editor-parity.spec.mjs` holds the canvas's DOM against the
			public route's for the same page (task 4.7).
		-->
		<component
			:is="shellBlockFor(block.widgetKey)"
			v-for="(block, i) in regions.header"
			:key="`header-${i}`"
			v-bind="shellPropsFor(block)" />

		<main id="pq-main" class="ac-app-main pq-site__main">
			<div>
			<!--
				THE SAME WRAPPERS THE PUBLIC ROUTE EMITS. `main` holds a plain
				`div`, which holds the `article`; the canvas omitted both and the
				parity test said so at the fourth character of the comparison.
			-->
			<article class="utrecht-article" data-testid="site-page">
			<!--
				THE PAGE TITLE, ON THE SAME RULE THE PUBLIC RENDERER USES.

				A page whose body declares no heading of its own gets an `h1`
				from its title; one with a hero does not, or the page would have
				two level-one headings. The canvas omitted this at first and the
				omission was invisible until the heading guardrail stayed silent
				on a page whose outline was genuinely broken — the canvas had no
				`h1` to jump from, so `h1 → h4` looked like `h4` alone.

				That is exactly what task 4.7's DOM comparison exists to catch,
				and it caught it.
			-->
			<div v-if="!bodyProvidesHeading" class="container">
				<h1 class="utrecht-heading-1" data-testid="page-title">
					{{ pageTitle }}
				</h1>
			</div>

				<!--
					ONE GRID PER REGION, exactly as the public route renders one
					grid per page.

					The first version wrapped every block in its own
					`<WidgetGrid>` so each could carry a selection outline, and
					the parity test rejected it immediately: the public page has
					ONE `widget-grid` container and the canvas had four, which
					means four `.container` columns and four independent grid
					flows. A canvas that lays out differently from the page is
					not previewing the page.

					Selection is by DELEGATION instead — a click is mapped back
					to a block through the `data-testid` the grid already emits
					— so the outline costs no element of its own.
				-->
				<WidgetGrid
					:widgets="gridWidgets"
					:glossary="glossary"
					:contributions="contributions" />

				<!--
					AN EMPTY REGION IS SHOWN, and only here. On a public page it
					renders nothing, which is correct; in an editor it would be
					an invisible drop target, and an author cannot put a block
					somewhere they cannot see.
				-->
				<div
					v-for="region in emptyBodyRegions"
					:key="`empty-${region}`"
					class="pq-canvas__empty"
					:data-region="region"
					@click="selectRegion(region)">
					{{ emptyLabel(region) }}
				</div>
			</article>
			</div>
		</main>

		<component
			:is="shellBlockFor(block.widgetKey)"
			v-for="(block, i) in regions.footer"
			:key="`footer-${i}`"
			v-bind="shellPropsFor(block)" />
	</div>
</template>

<script>
import BrandHeader from '../../site/components/BrandHeader.vue'
import FooterColumns from '../../site/components/FooterColumns.vue'
import WidgetGrid from '../../site/components/WidgetGrid.vue'
import { signInRoutes } from '../../site/lib/authApi.js'
import { withoutStyling } from '../../site/lib/blockProps.js'
import {
	footerContentOf,
	footerMenusOf,
	headerMenusOf,
	legalLinksOf,
	logoInitialOf,
	registerRouteOf,
} from '../../site/lib/shellData.js'

/**
 * The page, rendered by the components that will render it in public.
 *
 * @spec openspec/changes/portal-page-composition/tasks.md
 */
export default {
	name: 'EditorCanvas',

	components: { BrandHeader, FooterColumns, WidgetGrid },

	props: {
		/** The regions being edited. */
		regions: {
			type: Object,
			required: true,
		},

		/** The portal record, for the shell blocks' data. */
		site: {
			type: Object,
			default: () => ({}),
		},

		/** The portal's menus. */
		menus: {
			type: Array,
			default: () => [],
		},

		/** The portal's glossary terms, for a `glossary` block. */
		glossary: {
			type: Array,
			default: () => [],
		},

		/** The portal's contributions, for a `contributions` block. */
		contributions: {
			type: Array,
			default: () => [],
		},

		/** The canvas width in CSS pixels — the breakpoint being previewed. */
		width: {
			type: Number,
			default: 1440,
		},

		/** The selected block, as `{region, index}` or null. */
		selection: {
			type: Object,
			default: null,
		},

		/** The page's title, rendered as its `h1` when the body declares none. */
		pageTitle: {
			type: String,
			default: '',
		},

		/** The route being edited, for marking the active menu item. */
		route: {
			type: String,
			default: '/',
		},

		/** The portal auth base, for deriving the sign-in routes. */
		authBase: {
			type: String,
			default: '',
		},
	},

	emits: ['select'],

	computed: {
		/**
		 * The regions that live inside `main`.
		 *
		 * @return {Array<string>} The region keys.
		 */
		bodyRegions() {
			return ['hero', 'main', 'aside']
		},

		/**
		 * Every body block, in one list, in region order.
		 *
		 * The public route hands `WidgetGrid` one flat widget list, so the
		 * canvas has to as well — `slot` already carries which region a block
		 * belongs to, which is how the two stay the same structure.
		 *
		 * @return {Array<object>} The widget placements.
		 */
		gridWidgets() {
			const widgets = []
			let row = 0

			for (const region of this.bodyRegions) {
				for (const block of this.regions[region] || []) {
					widgets.push({
						id: block.id || '',
						widgetKey: block.widgetKey,
						slot: region,
						props: block.props || {},
						gridX: block.gridX || 0,
						gridY: row,
						gridWidth: block.gridWidth || 12,
						gridHeight: block.gridHeight || 4,
					})
					row += (block.gridHeight || 4)
				}
			}

			return widgets
		},

		/**
		 * Body regions with nothing in them.
		 *
		 * @return {Array<string>} The region keys.
		 */
		emptyBodyRegions() {
			return this.bodyRegions.filter((region) => (this.regions[region] || []).length === 0)
		},

		/**
		 * Whether the page's own body already declares a level-one heading.
		 *
		 * THE SAME RULE THE PUBLIC RENDERER APPLIES: a hero carries the page's
		 * `h1`, so a page with one must not also get a title heading. Diverging
		 * here would mean the canvas showed one heading structure and the
		 * public route another — and the author would tune the wrong outline.
		 *
		 * @return {boolean} Whether the body provides the heading.
		 */
		bodyProvidesHeading() {
			return [...(this.regions.hero || []), ...(this.regions.main || [])].some(
				(block) => block.widgetKey === 'hero',
			)
		},

		/**
		 * The portal's theme class, exactly as the public renderer derives it.
		 *
		 * @return {string} The class, or ''.
		 */
		themeClass() {
			const theme = String(this.site.theme || '')
			return theme ? `${theme}-theme` : ''
		},
	},

	methods: {
		/**
		 * The component for a shell block.
		 *
		 * @param {string} key The block key.
		 * @return {object|null} The component.
		 */
		shellBlockFor(key) {
			return { brandHeader: BrandHeader, footerColumns: FooterColumns }[key] || null
		},

		/**
		 * The props a shell block renders with, mirroring the public shell.
		 *
		 * @param {object} block The block.
		 * @return {object} The props.
		 */
		shellPropsFor(block) {
			const authored = withoutStyling((block && block.props) || {})

			if (block.widgetKey === 'brandHeader') {
				return {
					title: this.site.title || '',
					logo: this.site.logo || '',
					logoInitial: logoInitialOf(this.site.title),
					// THE SAME DERIVATIONS THE PUBLIC SHELL USES, from the same
					// module. They were re-derived here for one afternoon and
					// the parity test found three differences in that time: no
					// sign-in controls, the header's menus in the footer, and a
					// missing page title.
					menus: headerMenusOf(this.menus),
					currentRoute: this.route,
					singleBar: this.site.headerVariant === 'single',
					// NO SESSION ON THE CANVAS. An editor previews the page a
					// VISITOR gets, and the visitor the portal must get right is
					// the one who has not signed in — showing the author's own
					// signed-in header would hide the sign-in controls, which
					// are the most consequential thing in the bar.
					session: null,
					sessionLabel: '',
					signInRoutes: signInRoutes(this.site, this.authBase),
					registerRoute: registerRouteOf(this.site),
					...authored,
				}
			}

			if (block.widgetKey === 'footerColumns') {
				return {
					title: this.site.title || '',
					tagline: this.site.tagline || '',
					content: footerContentOf(this.site),
					menus: footerMenusOf(this.menus),
					legalLinks: legalLinksOf(this.site, this.menus),
					...authored,
				}
			}

			return authored
		},

		/**
		 * The footer's shape when the portal has never configured one.
		 *
		 * THE SHAPE, NOT AN EMPTY OBJECT. `FooterColumns` reads
		 * `content.socials.length`, and a portal that has never set a footer —
		 * which is most of them — would otherwise throw on first render.
		 *
		 * @return {object} An empty footer block.
		 */
		emptyFooter() {
			return {
				description: '',
				colophon: '',
				socials: [],
				badges: [],
				legalLinks: [],
				decoration: 'none',
			}
		},

		/**
		 * One block in the shape `WidgetGrid` expects.
		 *
		 * @param {object} block The block.
		 * @return {object} The widget placement.
		 */
		gridBlock(block) {
			return {
				id: block.id || '',
				widgetKey: block.widgetKey,
				props: block.props || {},
				gridX: block.gridX || 0,
				gridY: 0,
				gridWidth: block.gridWidth || 12,
				gridHeight: block.gridHeight || 4,
			}
		},

		/**
		 * Map a click anywhere on the canvas back to the block it landed in.
		 *
		 * DELEGATION RATHER THAN A WRAPPER PER BLOCK, because a wrapper is an
		 * element the public route does not have — and the parity test rejects
		 * exactly that. `capture` so a click on a LINK inside a block selects
		 * the block instead of navigating: an editor that follows its own links
		 * loses the author's place and, on a form block, their work.
		 *
		 * @param {MouseEvent} event The click.
		 * @return {void}
		 */
		onCanvasClick(event) {
			const header = event.target.closest('header.ac-header')
			if (header) {
				this.selectFirst('header')
				return
			}

			const footer = event.target.closest('footer.ac-footer')
			if (footer) {
				this.selectFirst('footer')
				return
			}

			const cell = event.target.closest('[data-widget-key]')
			if (!cell) {
				return
			}

			// The block's identity is its position among the body blocks, which
			// is what `gridWidgets` flattened in region order.
			const cells = [...this.$el.querySelectorAll('[data-widget-key]')]
			const index = cells.indexOf(cell)
			const block = this.gridWidgets[index]
			if (!block) {
				return
			}

			// Its position WITHIN its region is the number of blocks before it
			// that share the same slot — `gridWidgets` flattened them in region
			// order, so counting is the whole of the mapping.
			const within = this.gridWidgets
				.slice(0, index)
				.filter((widget) => widget.slot === block.slot).length

			this.$emit('select', { region: block.slot, index: within })
		},

		/**
		 * Select the first block of a region.
		 *
		 * @param {string} region The region.
		 * @return {void}
		 */
		selectFirst(region) {
			if ((this.regions[region] || []).length > 0) {
				this.$emit('select', { region, index: 0 })
			}
		},

		/**
		 * Whether a block is the selected one.
		 *
		 * @param {string} region The region.
		 * @param {number} index  The index.
		 * @return {boolean} Whether selected.
		 */
		isSelected(region, index) {
			return Boolean(
				this.selection
					&& this.selection.region === region
					&& this.selection.index === index,
			)
		},

		/**
		 * Select a block.
		 *
		 * `click.capture` on the wrapper, so a click on a LINK inside a block
		 * selects the block instead of navigating. An editor that follows its
		 * own links loses the author's place and, on a form block, their work.
		 *
		 * @param {string} region The region.
		 * @param {number} index  The index.
		 * @return {void}
		 */
		select(region, index) {
			this.$emit('select', { region, index })
		},

		/**
		 * Select a region with nothing in it.
		 *
		 * @param {string} region The region.
		 * @return {void}
		 */
		selectRegion(region) {
			this.$emit('select', { region, index: -1 })
		},

		/**
		 * What an empty region invites the author to do.
		 *
		 * @param {string} region The region.
		 * @return {string} The label.
		 */
		emptyLabel(region) {
			return `${region} — nog leeg. Kies links een blok om toe te voegen.`
		},
	},
}
</script>

<style scoped>
/*
 * THE CANVAS ADDS NO STYLING TO THE BLOCKS, only around them.
 *
 * Everything here is an outline or an affordance on the wrapper; nothing
 * touches a block's own box, because a canvas that restyles what it previews
 * is a canvas whose preview is wrong. The selection ring is an `outline`
 * rather than a `border` for exactly that reason — a border would take space
 * and reflow the block being edited.
 */
.pq-canvas {
	margin: 0 auto;
	background: #fff;
	box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.08);
	overflow: hidden;
}

.pq-canvas__block {
	position: relative;
	cursor: pointer;
}

.pq-canvas__block[data-selected='true'] {
	outline: 2px solid #0b5cff;
	outline-offset: -2px;
}

.pq-canvas__block:hover:not([data-selected='true']) {
	outline: 1px dashed rgba(11, 92, 255, 0.6);
	outline-offset: -1px;
}

.pq-canvas__empty {
	margin: 8px;
	padding: 24px;
	border: 1px dashed rgba(0, 0, 0, 0.25);
	border-radius: 4px;
	color: rgba(0, 0, 0, 0.6);
	font-size: 13px;
	text-align: center;
	cursor: pointer;
}
</style>
