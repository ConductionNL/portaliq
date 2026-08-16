<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div class="pq-grid" data-testid="widget-grid">
		<div
			v-for="widget in widgets"
			:key="widget.id || `${widget.gridX}-${widget.gridY}`"
			class="pq-grid__cell"
			:data-testid="`widget-${widget.id || widget.widgetKey}`"
			:data-widget-key="widget.widgetKey"
			:style="cellStyle(widget)">
			<component
				:is="componentFor(widget.widgetKey)"
				v-if="componentFor(widget.widgetKey)"
				v-bind="propsFor(widget)" />

			<!-- Anything not public, or not known, degrades to an inert
			     placeholder. It does NOT throw: a public page with one bad
			     widget must still show its other three, and a page that blanks
			     is a worse failure than a visibly missing tile. -->
			<p v-else class="pq-grid__placeholder" data-testid="widget-placeholder">
				{{ placeholderText(widget.widgetKey) }}
			</p>
		</div>
	</div>
</template>

<script>
import { siteBlockRegistry } from '@conduction/nextcloud-vue/public'
import MarkdownBlock from './MarkdownBlock.vue'

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
 */
const PUBLIC_WIDGETS = {
	markdown: MarkdownBlock,
	...siteBlockRegistry,
}

export default {
	name: 'WidgetGrid',

	components: { MarkdownBlock },

	props: {
		/** Widget placements in the canonical manifest-v2 widgetEntry shape. */
		widgets: {
			type: Array,
			default: () => [],
		},
	},

	methods: {
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
		 * The geometry is the manifest's, not a portal variant: 12 columns,
		 * `gridX`/`gridY` zero-based, `gridWidth`/`gridHeight` spans. A page
		 * authored in OpenBuild's Page Designer therefore lands in the same
		 * cells here.
		 *
		 * `gridX + gridWidth > 12` is clamped rather than thrown on. The
		 * manifest validator already rejects it at author time with the
		 * canonical message; at render time on a public page, clamping shows
		 * the content and a throw shows nothing.
		 *
		 * @param {object} widget The placement.
		 * @return {object} The style bindings.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		cellStyle(widget) {
			const x = Math.max(0, Math.min(11, Number(widget.gridX) || 0))
			const width = Math.max(
				1,
				Math.min(12 - x, Number(widget.gridWidth) || 12),
			)
			const height = Math.max(1, Number(widget.gridHeight) || 1)

			return {
				gridColumn: `${x + 1} / span ${width}`,
				gridRow: `${(Number(widget.gridY) || 0) + 1} / span ${height}`,
			}
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
