<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<!--
		DECORATION, AND MARKED AS SUCH. The whole scene carries no information a
		visitor needs, so it is `aria-hidden` and contributes nothing to the
		page's text or tab order.
	-->
	<div class="pq-canal" aria-hidden="true" data-testid="footer-canal">
		<!-- The roofline. `overflow: hidden` on the strip is what clips the tall
		     houses, so a house may be taller than the band it stands in. -->
		<div class="pq-canal__skyline">
			<span
				v-for="(house, i) in houses"
				:key="i"
				class="pq-canal__house"
				:style="{ inlineSize: house.w + 'px', blockSize: house.h + 'px' }">
				<svg
					:viewBox="shapes[house.shape].viewBox"
					preserveAspectRatio="none"
					focusable="false">
					<path :d="shapes[house.shape].path" class="pq-canal__facade" />
					<rect
						v-for="(r, j) in shapes[house.shape].rects"
						:key="j"
						:x="r.x"
						:y="r.y"
						:width="r.w"
						:height="r.h"
						:class="`pq-canal__${r.role}`"
						:style="{ opacity: r.opacity }" />
				</svg>
			</span>
		</div>

		<!-- The quay: a narrow band between the houses and the water, with a
		     bicycle and a bench on it. -->
		<div class="pq-canal__quay">
			<svg
				v-for="(item, i) in quay"
				:key="i"
				class="pq-canal__quay-item"
				:viewBox="item.viewBox"
				:width="item.w"
				:style="{ insetInlineStart: item.at }"
				focusable="false">
				<g
					v-if="item.kind === 'bicycle'"
					class="pq-canal__ink"
					fill="none"
					stroke-width="1.4"
					stroke-linecap="round">
					<circle cx="4" cy="12" r="3" />
					<circle cx="18" cy="12" r="3" />
					<line x1="4" y1="12" x2="11" y2="6" />
					<line x1="11" y1="6" x2="18" y2="12" />
					<line x1="11" y1="6" x2="14" y2="12" />
					<line x1="11" y1="3" x2="14" y2="3" />
				</g>
				<template v-else>
					<rect
						x="0"
						y="6"
						width="38"
						height="6"
						rx="2"
						:class="
							item.accent
								? 'pq-canal__bench-accent'
								: 'pq-canal__bench'
						" />
					<path
						d="M 5,6 L 9,2 L 29,2 L 33,6 Z"
						:class="
							item.accent
								? 'pq-canal__bench-accent'
								: 'pq-canal__bench'
						" />
					<rect
						x="11"
						y="3"
						width="6"
						height="3"
						class="pq-canal__bench-window" />
				</template>
			</svg>
		</div>
	</div>
</template>

<script>
/**
 * The canal scene above the footer: a roofline, a quay, and the water's ripples.
 *
 * WHY THE GEOMETRY IS DATA AND NOT A PICTURE.
 *
 * This is Conduction's own illustration, reproduced from the running reference
 * rather than redrawn: five gabled facades, a fixed sequence of thirty-two
 * houses at five widths and five heights, and two objects on the quay. Redrawing
 * canal houses by hand would have been inventing a brand asset rather than
 * implementing one, and the difference matters — a house one gable-step out is
 * still recognisably wrong.
 *
 * Every COLOUR is a token and every band is optional, so nothing here is
 * Conduction-only by construction: the facades take the brand's accent, the
 * windows a warm light and a dark, the quay its own surface. A municipal portal
 * that does not ask for a canal simply does not render one — `App.vue` mounts
 * this only when the portal's footer names the decoration.
 *
 * The waves live in `css/site-theme.css` as a background on the water band
 * itself, because they belong BEHIND the footer's content rather than beside it.
 *
 * @spec openspec/changes/portal-page-composition/specs/portal-page-composition/spec.md#requirement-every-region-of-a-portal-page-must-be-composed-from-widgets
 */
export default {
	name: 'FooterCanal',

	data() {
		return {
			/**
			 * The five facades, each a gabled outline plus its windows.
			 *
			 * `role` decides which token paints a rectangle — a lit window, a
			 * dark one, or a door — and `opacity` is the per-window variation
			 * that stops a terrace of thirty-two houses looking printed.
			 */
			shapes: {
				h1: {
					viewBox: '0 -10 90 180',
					path: 'M 0,170 L 0,30 L 8,30 L 8,20 L 16,20 L 16,10 L 24,10 L 24,4 L 66,4 L 66,10 L 74,10 L 74,20 L 82,20 L 82,30 L 90,30 L 90,170 Z',
					rects: [
						{ x: 12, y: 60, w: 14, h: 20, role: 'dark', opacity: 0.35 },
						{ x: 38, y: 60, w: 14, h: 20, role: 'lit', opacity: 0.85 },
						{ x: 64, y: 60, w: 14, h: 20, role: 'dark', opacity: 0.35 },
						{ x: 12, y: 100, w: 14, h: 20, role: 'lit', opacity: 0.6 },
						{ x: 38, y: 100, w: 14, h: 20, role: 'dark', opacity: 0.35 },
						{ x: 64, y: 100, w: 14, h: 20, role: 'dark', opacity: 0.35 },
						{ x: 37, y: 130, w: 16, h: 34, role: 'door', opacity: 0.55 },
					],
				},

				h2: {
					viewBox: '0 0 100 220',
					path: 'M 0,220 L 0,58 L 8,58 L 8,47 L 16,47 L 16,36 L 24,36 L 24,25 L 32,25 L 32,14 L 50,4 L 68,14 L 68,25 L 76,25 L 76,36 L 84,36 L 84,47 L 92,47 L 92,58 L 100,58 L 100,220 Z',
					rects: [
						{ x: 14, y: 80, w: 14, h: 20, role: 'lit', opacity: 0.85 },
						{ x: 43, y: 80, w: 14, h: 20, role: 'dark', opacity: 0.35 },
						{ x: 72, y: 80, w: 14, h: 20, role: 'lit', opacity: 0.55 },
						{ x: 14, y: 115, w: 14, h: 20, role: 'dark', opacity: 0.35 },
						{ x: 43, y: 115, w: 14, h: 20, role: 'dark', opacity: 0.35 },
						{ x: 72, y: 115, w: 14, h: 20, role: 'dark', opacity: 0.35 },
						{ x: 14, y: 150, w: 14, h: 20, role: 'dark', opacity: 0.35 },
						{ x: 43, y: 150, w: 14, h: 20, role: 'lit', opacity: 0.7 },
						{ x: 72, y: 150, w: 14, h: 20, role: 'dark', opacity: 0.35 },
						{ x: 42, y: 180, w: 16, h: 34, role: 'door', opacity: 0.55 },
					],
				},

				h3: {
					viewBox: '0 -2 80 202',
					path: 'M 0,200 L 0,38 L 8,38 L 8,26 L 16,26 L 16,14 L 24,14 L 24,8 L 56,8 L 56,14 L 64,14 L 64,26 L 72,26 L 72,38 L 80,38 L 80,200 Z',
					rects: [
						{ x: 22, y: 68, w: 10, h: 16, role: 'lit', opacity: 0.95 },
						{ x: 48, y: 68, w: 10, h: 16, role: 'dark', opacity: 0.35 },
						{ x: 22, y: 98, w: 10, h: 16, role: 'dark', opacity: 0.35 },
						{ x: 48, y: 98, w: 10, h: 16, role: 'lit', opacity: 0.6 },
						{ x: 22, y: 128, w: 10, h: 16, role: 'dark', opacity: 0.35 },
						{ x: 48, y: 128, w: 10, h: 16, role: 'dark', opacity: 0.35 },
						{ x: 32, y: 160, w: 16, h: 34, role: 'door', opacity: 0.55 },
					],
				},

				h4: {
					viewBox: '0 0 70 240',
					path: 'M 0,240 L 0,48 L 5,48 L 5,41 L 10,41 L 10,34 L 15,34 L 15,27 L 20,27 L 20,20 L 25,20 L 25,15 L 45,15 L 45,20 L 50,20 L 50,27 L 55,27 L 55,34 L 60,34 L 60,41 L 65,41 L 65,48 L 70,48 L 70,240 Z',
					rects: [
						{ x: 14, y: 70, w: 12, h: 18, role: 'lit', opacity: 0.55 },
						{ x: 44, y: 70, w: 12, h: 18, role: 'lit', opacity: 0.85 },
						{ x: 14, y: 100, w: 12, h: 18, role: 'dark', opacity: 0.35 },
						{ x: 44, y: 100, w: 12, h: 18, role: 'dark', opacity: 0.35 },
						{ x: 14, y: 130, w: 12, h: 18, role: 'lit', opacity: 0.7 },
						{ x: 44, y: 130, w: 12, h: 18, role: 'dark', opacity: 0.35 },
						{ x: 14, y: 160, w: 12, h: 18, role: 'dark', opacity: 0.35 },
						{ x: 44, y: 160, w: 12, h: 18, role: 'dark', opacity: 0.35 },
						{ x: 27, y: 200, w: 16, h: 34, role: 'door', opacity: 0.55 },
					],
				},

				h5: {
					viewBox: '0 0 95 210',
					path: 'M 0,210 L 0,55 L 8,55 L 8,44 L 16,44 L 16,33 L 24,33 L 24,22 L 32,22 L 32,11 L 35,11 L 35,6 L 59,6 L 59,11 L 63,11 L 63,22 L 71,22 L 71,33 L 79,33 L 79,44 L 87,44 L 87,55 L 95,55 L 95,210 Z',
					rects: [
						{ x: 13, y: 75, w: 14, h: 20, role: 'dark', opacity: 0.35 },
						{ x: 40, y: 75, w: 14, h: 20, role: 'lit', opacity: 0.65 },
						{ x: 68, y: 75, w: 14, h: 20, role: 'dark', opacity: 0.35 },
						{ x: 13, y: 110, w: 14, h: 20, role: 'lit', opacity: 0.8 },
						{ x: 40, y: 110, w: 14, h: 20, role: 'dark', opacity: 0.35 },
						{ x: 68, y: 110, w: 14, h: 20, role: 'dark', opacity: 0.35 },
						{ x: 13, y: 145, w: 14, h: 20, role: 'dark', opacity: 0.35 },
						{ x: 40, y: 145, w: 14, h: 20, role: 'dark', opacity: 0.35 },
						{ x: 68, y: 145, w: 14, h: 20, role: 'lit', opacity: 0.55 },
						{
							x: 39.5,
							y: 175,
							w: 16,
							h: 34,
							role: 'door',
							opacity: 0.55,
						},
					],
				},
			},

			/**
			 * The terrace, in order.
			 *
			 * A FIXED SEQUENCE, not a random one: the same page must draw the
			 * same street on every render, and a shuffled skyline would make
			 * every screenshot diff a false positive.
			 */
			houses: [
				{ shape: 'h1', w: 54, h: 102 },
				{ shape: 'h2', w: 60, h: 132 },
				{ shape: 'h3', w: 48, h: 120 },
				{ shape: 'h4', w: 42, h: 144 },
				{ shape: 'h5', w: 57, h: 126 },
				{ shape: 'h4', w: 42, h: 144 },
				{ shape: 'h5', w: 57, h: 126 },
				{ shape: 'h1', w: 54, h: 102 },
				{ shape: 'h4', w: 42, h: 144 },
				{ shape: 'h3', w: 48, h: 120 },
				{ shape: 'h4', w: 42, h: 144 },
				{ shape: 'h2', w: 60, h: 132 },
				{ shape: 'h2', w: 60, h: 132 },
				{ shape: 'h5', w: 57, h: 126 },
				{ shape: 'h1', w: 54, h: 102 },
				{ shape: 'h4', w: 42, h: 144 },
				{ shape: 'h5', w: 57, h: 126 },
				{ shape: 'h3', w: 48, h: 120 },
				{ shape: 'h5', w: 57, h: 126 },
				{ shape: 'h3', w: 48, h: 120 },
				{ shape: 'h2', w: 60, h: 132 },
				{ shape: 'h3', w: 48, h: 120 },
				{ shape: 'h2', w: 60, h: 132 },
				{ shape: 'h4', w: 42, h: 144 },
				{ shape: 'h3', w: 48, h: 120 },
				{ shape: 'h1', w: 54, h: 102 },
				{ shape: 'h2', w: 60, h: 132 },
				{ shape: 'h2', w: 60, h: 132 },
				{ shape: 'h3', w: 48, h: 120 },
				{ shape: 'h5', w: 57, h: 126 },
				{ shape: 'h5', w: 57, h: 126 },
				{ shape: 'h3', w: 48, h: 120 },
			],

			/**
			 * What stands on the quay, placed as a percentage of its width.
			 *
			 * Percentages rather than the reference's pixel offsets: those were
			 * measured at 1440px and would bunch everything at the left edge of
			 * a wider viewport.
			 */
			quay: [
				{
					kind: 'bench',
					viewBox: '0 0 38 16',
					w: 38,
					at: '4%',
					accent: true,
				},
				{ kind: 'bicycle', viewBox: '0 -2 22 18', w: 22, at: '35%' },
				{ kind: 'bench', viewBox: '0 0 38 16', w: 38, at: '66%' },
				{ kind: 'bicycle', viewBox: '0 -2 22 18', w: 22, at: '89%' },
			],
		}
	},
}
</script>
