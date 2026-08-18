<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section class="pq-panel" data-testid="editor-library">
		<h2 class="pq-panel__title">Blokken</h2>

		<label class="pq-panel__field">
			<span>Regio</span>
			<select v-model="target" data-testid="library-region">
				<option v-for="region in regions" :key="region" :value="region">
					{{ region }}
				</option>
			</select>
		</label>

		<!--
			GROUPED BY CATEGORY, AND EACH ENTRY SAYS WHAT IT DOES (task 4.2).
			"Kaarten" is a name; "een rij kaarten die elk naar een pagina of
			dienst verwijzen" is what somebody choosing from a list needs. A
			library of names is a library you have to already know.
		-->
		<div v-for="group in groups" :key="group.category" class="pq-panel__group">
			<h3 class="pq-panel__group-title">{{ group.category }}</h3>
			<ul class="pq-panel__list">
				<li v-for="block in group.blocks" :key="block.key">
					<button
						type="button"
						class="pq-panel__block"
						:data-testid="`library-add-${block.key}`"
						@click="$emit('insert', { region: target, key: block.key })">
						<span class="pq-panel__block-label">{{ block.label }}</span>
						<span class="pq-panel__block-description">{{
							block.description
						}}</span>
					</button>
				</li>
			</ul>
		</div>

		<!--
			A REGION WITH NOTHING TO OFFER SAYS SO. An empty panel with no
			explanation reads as a broken editor, and the reason — no block
			declares this region — is something only the code knows.
		-->
		<p v-if="groups.length === 0" class="pq-panel__empty">
			Geen blokken beschikbaar voor deze regio.
		</p>
	</section>
</template>

<script>
import { blocksForRegion } from '../../site/lib/blockCatalog.js'
import { REGIONS } from '../../site/lib/regions.js'

/**
 * What can be placed, where.
 *
 * Reads the block catalogue rather than a list of its own, so a block that
 * gains a region or a description says so here without this file changing.
 *
 * @spec openspec/changes/portal-page-composition/tasks.md
 */
export default {
	name: 'BlockLibrary',

	props: {
		/** The region currently selected in the canvas, if any. */
		region: {
			type: String,
			default: 'main',
		},
	},

	emits: ['insert'],

	data() {
		return { target: this.region || 'main' }
	},

	computed: {
		/**
		 * @return {Array<string>} Every region a block can go in.
		 */
		regions() {
			return REGIONS
		},

		/**
		 * @return {Array<object>} The placeable blocks, grouped by category.
		 */
		groups() {
			return blocksForRegion(this.target)
		},
	},

	watch: {
		/**
		 * Follow the canvas selection.
		 *
		 * Selecting a block in the footer and then adding one should add it to
		 * the footer — an author's next action is nearly always in the region
		 * they were just looking at.
		 *
		 * @param {string} value The newly selected region.
		 * @return {void}
		 */
		region(value) {
			if (value) {
				this.target = value
			}
		},
	},
}
</script>
