<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section class="pq-panel" data-testid="editor-layers">
		<h2 class="pq-panel__title">Opbouw</h2>

		<!--
			REGION → BLOCK, and nothing deeper (task 4.4). The tree mirrors the
			structure an author can actually change; showing the DOM inside a
			block would offer a level of detail nothing here can edit, which is
			how a layer tree becomes a thing people close.
		-->
		<ul class="pq-tree">
			<li v-for="region in regions" :key="region">
				<span class="pq-tree__region">{{ region }}</span>

				<ul>
					<li v-for="(block, i) in blocks[region]" :key="`${region}-${i}`">
						<button
							type="button"
							class="pq-tree__block"
							:data-selected="isSelected(region, i)"
							:data-testid="`layer-${region}-${i}`"
							@click="$emit('select', { region, index: i })">
							{{ labelFor(block) }}
						</button>

						<!--
							MOVING IS BUTTONS, NOT DRAG. Drag-and-drop in a
							layer tree needs a drop indicator, keyboard
							equivalents and an accessible announcement to be
							usable at all; two buttons are usable by everyone
							on the first try and are not a worse version of the
							same thing.
						-->
						<span class="pq-tree__moves">
							<button
								type="button"
								:disabled="i === 0"
								:data-testid="`layer-up-${region}-${i}`"
								aria-label="Naar boven"
								@click="$emit('move', { region, index: i, to: i - 1 })">
								↑
							</button>
							<button
								type="button"
								:disabled="i === blocks[region].length - 1"
								:data-testid="`layer-down-${region}-${i}`"
								aria-label="Naar beneden"
								@click="$emit('move', { region, index: i, to: i + 1 })">
								↓
							</button>
						</span>
					</li>

					<li v-if="blocks[region].length === 0" class="pq-tree__empty">
						leeg
					</li>
				</ul>
			</li>
		</ul>
	</section>
</template>

<script>
import { blockInfo } from '../../site/lib/blockCatalog.js'
import { REGIONS } from '../../site/lib/regions.js'

/**
 * The page's structure, and the other end of the selection.
 *
 * SELECTION IS SYNCHRONISED BOTH WAYS (task 4.4): this emits the same
 * `{region, index}` the canvas does and reads the same prop back, so there is
 * one selected block rather than two ideas about which one it is.
 *
 * @spec openspec/changes/portal-page-composition/tasks.md
 */
export default {
	name: 'LayerTree',

	props: {
		/** The regions being edited. */
		blocks: {
			type: Object,
			required: true,
		},

		/** The selected block, as `{region, index}` or null. */
		selection: {
			type: Object,
			default: null,
		},
	},

	emits: ['select', 'move'],

	computed: {
		/**
		 * @return {Array<string>} The regions, in render order.
		 */
		regions() {
			return REGIONS
		},
	},

	methods: {
		/**
		 * What to call a block in the tree.
		 *
		 * The block's own title wins when it has one — "Welkom bij het loket"
		 * tells an author which block this is; "Hero" tells them what kind it
		 * is, which they can see from its position anyway.
		 *
		 * @param {object} block The block.
		 * @return {string} The label.
		 */
		labelFor(block) {
			const info = blockInfo(block.widgetKey)
			const title = (block.props && (block.props.title || block.props.label)) || ''
			const kind = info ? info.label : block.widgetKey

			return title ? `${kind} — ${title}` : kind
		},

		/**
		 * @param {string} region The region.
		 * @param {number} index  The index.
		 * @return {boolean} Whether this block is selected.
		 */
		isSelected(region, index) {
			return Boolean(
				this.selection
					&& this.selection.region === region
					&& this.selection.index === index,
			)
		},
	},
}
</script>
