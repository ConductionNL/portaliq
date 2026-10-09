<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The start tiles of a portal (site-nlds-widget-palette T10, design D6):
	every action a contributing app offers with a summary, drawn as the Home
	board's "Direct regelen" list, one link per action to its page. A
	signed-out visitor who picks one is asked to sign in first by the
	signed-in area. Nothing renders while there are no tiles.
-->
<template>
	<div v-if="tasks.length > 0" class="nl-start-tiles">
		<NlQuickTasks
			:heading="heading"
			:items="tasks"
			:columns="columns"
			:moreLabel="moreLabel"
			:moreHref="moreHref"
			:overlap="overlap"
			data-testid="nl-start-tiles"
			@navigate="$emit('navigate', $event)" />
	</div>
</template>

<script>
import NlQuickTasks from '../nlQuickTasks/NlQuickTasks.vue'
import { resolveApiBase } from '../../lib/contentApi.js'
import { fetchStartTiles, tilesToTasks } from '../../lib/startTiles.js'

import '@utrecht/link-css/dist/index.css'

export default {
	name: 'NlStartTiles',

	components: { NlQuickTasks },

	props: {
		/** The card's heading. */
		heading: { type: String, default: 'Direct regelen' },
		/** Columns on a wide screen: 2, 3 or 4. */
		columns: { type: [Number, String], default: 3 },
		/** The link under the tiles. */
		moreLabel: { type: String, default: '' },
		/** Its address. */
		moreHref: { type: String, default: '' },
		/** Pull the card up over the band above it. */
		overlap: { type: Boolean, default: false },
		/** The serving portal, from the host. */
		portal: { type: String, default: '' },
	},

	emits: ['navigate'],

	data() {
		return { tiles: [] }
	},

	computed: {
		/**
		 * @return {Array<{label: string, href: string}>} The tiles as tasks.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-contribution-contract/spec.md#requirement-an-action-may-offer-itself-as-a-start-tile-with-a-summary-and-its-audiences-req-snw-020
		 */
		tasks() {
			return tilesToTasks(this.tiles)
		},
	},

	watch: {
		portal: 'load',
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the portal's start tiles.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-contribution-contract/spec.md#requirement-an-action-may-offer-itself-as-a-start-tile-with-a-summary-and-its-audiences-req-snw-020
		 */
		async load() {
			this.tiles = await fetchStartTiles(
				this.portal,
				(url, options) =>
					fetch(new URL(url, window.location.origin), options),
				resolveApiBase(),
			)
		},
	},
}
</script>
