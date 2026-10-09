<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!--
		The box around one block of a contribution page, or around all of
		them (mijn-overview-follows-the-boards), drawn ONLY where the page asks
		for a layout: a column, a frame or a link in the heading row.
		Otherwise the content renders as it is, with no element of its own,
		so every page that declares none of these keys keeps the exact markup
		it had (the case page styles its action buttons as direct children of
		the page).
	-->
	<div
		v-if="wrap"
		:class="
			kind === 'grid' ? ['pq-contribution-page__grid', ...classes] : classes
		"
		:style="place"
		:data-block="kind === 'block' && type ? type : undefined"
		:data-testid="
			kind === 'grid' ? 'contribution-page-grid' : 'contribution-page-block'
		">
		<slot />
		<a
			v-if="kind === 'block' && more && more.label"
			class="utrecht-link pq-block__more"
			:href="more.href"
			data-testid="contribution-page-block-more"
			@click="open">
			{{ more.label }}
		</a>
	</div>
	<slot v-else />
</template>

<script>
/**
 * As `kind: 'grid'` the two-column grid the blocks stand in; as
 * `kind: 'block'` one block, placed in that grid by custom properties from
 * pageLayout.js, with its frame and its "Alle ..." link.
 *
 * @spec openspec/changes/mijn-overview-follows-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-block-may-stand-in-a-column-and-in-a-frame
 */
export default {
	name: 'BlockShell',

	props: {
		/** `grid` for the page's blocks together, `block` for one. */
		kind: { type: String, default: 'block' },
		/** Whether to draw the box at all. */
		wrap: { type: Boolean, default: false },
		/** The wrapper's classes. */
		classes: { type: Array, default: () => [] },
		/** The wrapper's custom properties (its place in the grid). */
		place: { type: Object, default: undefined },
		/** `{label, route, href}` of the block's link, or null. */
		more: { type: Object, default: null },
		/** The block type, for tests and styling hooks. */
		type: { type: String, default: '' },
	},

	emits: ['navigate'],

	methods: {
		/**
		 * Follow the link inside the site.
		 *
		 * @param {MouseEvent} event The click.
		 * @return {void}
		 * @spec openspec/changes/mijn-overview-follows-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-block-may-carry-a-link-to-all-of-it
		 */
		open(event) {
			if (!this.more?.route) {
				return
			}
			event.preventDefault()
			this.$emit('navigate', this.more.route)
		},
	},
}
</script>

<style>
/* Not scoped: the box holds other components' roots.

   THE TWO COLUMNS (boards MijnOverzicht): from tablet width the blocks stand
   in a grid of a wider main column and a side column; each block takes the
   place pageLayout.js gives it. On a phone the grid is one column in the
   order of the document. */
.pq-contribution-page__grid {
	display: flex;
	flex-direction: column;
	gap: 28px;
}

.pq-contribution-page__grid > .pq-block {
	min-inline-size: 0;
}

@media (min-width: 768px) {
	.pq-contribution-page__grid {
		display: grid;
		grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
		gap: 24px;
		align-items: start;
	}

	.pq-contribution-page__grid > .pq-block {
		grid-column: var(--pq-block-column, 1 / -1);
		grid-row: var(--pq-block-row, auto);
	}
}

/* Inside a band of columns a block's own bottom margin would double the gap. */
.pq-contribution-page__grid > .pq-block > * {
	margin-block-end: 0;
}

.pq-block > *:last-child {
	margin-block-end: 0;
}

/* A frame: the board's card, a line or a tinted ground, from the theme. */
.pq-block--framed {
	box-sizing: border-box;
	padding: 20px 22px;
	border-radius: var(--nldesign-website-border-radius-large, 8px);
}

.pq-block--line {
	border: 1px solid
		var(--nldesign-color-border, var(--utrecht-color-grey-90, currentcolor));
	background: var(--utrecht-document-background-color, Canvas);
}

.pq-block--tinted {
	background: var(
		--thematiq-surface-color,
		var(
			--nldesign-component-content-surface-background-color,
			var(--nldesign-color-surface, Canvas)
		)
	);
}

/* The "Alle ..." link: under the block, or at the end of its heading row. */
.pq-block {
	position: relative;
}

.pq-block__more {
	display: inline-block;
	margin-block-start: 12px;
	font-weight: 600;
}

.pq-block--more-heading > .pq-block__more {
	position: absolute;
	inset-block-start: 0;
	inset-inline-end: 0;
	margin: 0;
	line-height: 2;
}

.pq-block--framed.pq-block--more-heading > .pq-block__more {
	inset-block-start: 20px;
	inset-inline-end: 22px;
}

/* A strip keeps room for the link at the end of its line. */
.pq-block--more-heading > .pq-kpi--strip {
	padding-inline-end: 6em;
}

/* The block's heading leaves room for the link at its end. */
.pq-block--more-heading > * > :is(h2, h3, h4):first-child,
.pq-block--more-heading > :is(h2, h3, h4):first-child {
	padding-inline-end: 9em;
}
</style>
