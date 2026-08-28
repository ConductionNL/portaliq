<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  WidgetPaletteDialog — the page designer's widget palette.

  Its own file per ADR-004's modal-isolation rule (NcDialog-based dialogs live
  under src/dialogs/), which is also what keeps the designer readable: the
  palette is a list with its own selection state and has no business sharing a
  component with the grid.

  THE WHOLE CATALOGUE IS OFFERED, and the entries a published page will not
  mount are marked rather than removed. See src/lib/pageWidgetCatalogue.js for
  why marking beats hiding.

  @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-the-palette-must-mark-widgets-that-cannot-render-on-a-public-page
-->
<template>
	<NcDialog
		:name="t('portaliq', 'Add a widget')"
		:open="open"
		size="normal"
		data-testid="widget-palette"
		@update:open="$emit('update:open', $event)">
		<p class="palette__intro">
			{{
				t(
					'portaliq',
					'Pick a widget to place on this page. You can move and resize it afterwards.',
				)
			}}
		</p>

		<ul class="palette__list">
			<li v-for="entry in entries" :key="entry.key" class="palette__item">
				<button
					type="button"
					class="palette__button"
					:class="{ 'palette__button--warned': !entry.publicSafe }"
					:data-testid="`widget-palette-${entry.key}`"
					:data-public="entry.publicSafe ? 'true' : 'false'"
					@click="choose(entry)">
					<span class="palette__label">{{ entry.label }}</span>
					<span class="palette__key">{{ entry.key }}</span>
					<!--
						The reason travels with the entry rather than sitting in
						a legend somewhere: an author reading one row has to be
						able to tell, from that row, what placing it will do.
					-->
					<span v-if="!entry.publicSafe" class="palette__reason">
						{{ entry.reason }}
					</span>
				</button>
			</li>
		</ul>

		<template #actions>
			<NcButton
				data-testid="widget-palette-close"
				@click="$emit('update:open', false)">
				{{ t('portaliq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { widgetCatalogue } from '../lib/pageWidgetCatalogue.js'

export default {
	name: 'WidgetPaletteDialog',

	components: {
		NcButton,
		NcDialog,
	},

	props: {
		/** Whether the palette is open. */
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:open', 'choose'],

	computed: {
		/**
		 * The catalogue, public entries first.
		 *
		 * @return {Array<object>} The entries.
		 */
		entries() {
			return widgetCatalogue()
		},
	},

	methods: {
		/**
		 * Hand the chosen key to the designer and close.
		 *
		 * @param {object} entry The catalogue entry.
		 * @return {void}
		 */
		choose(entry) {
			this.$emit('choose', entry.key)
			this.$emit('update:open', false)
		},
	},
}
</script>

<style scoped>
.palette__intro {
	margin-bottom: 12px;
	color: var(--color-text-maxcontrast);
}

.palette__list {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 8px;
	list-style: none;
	margin: 0;
	padding: 0;
	max-height: 60vh;
	overflow-y: auto;
}

.palette__button {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 2px;
	width: 100%;
	padding: 10px 12px;
	text-align: left;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	cursor: pointer;
}

.palette__button:hover,
.palette__button:focus-visible {
	background: var(--color-background-hover);
}

.palette__button--warned {
	/* Marked, not disabled. The widget is placeable — apps do place it — and
	   an author who wants it on a page that is not public is not making a
	   mistake. What they must not be able to do is place it unknowingly. */
	border-style: dashed;
}

.palette__label {
	font-weight: bold;
}

.palette__key {
	color: var(--color-text-maxcontrast);
	font-family: monospace;
	font-size: 0.85em;
}

.palette__reason {
	color: var(--color-warning-text, var(--color-text-maxcontrast));
	font-size: 0.85em;
}
</style>
