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

  @spec openspec/specs/portal-page-designer/spec.md#requirement-the-palette-must-mark-widgets-that-cannot-render-on-a-public-page
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

		<!--
			The search, and the count it announces. The count is in a live
			region because an author who types and reads nothing has no way to
			tell a narrow search from a broken one; a screen reader says "3
			widgets" as the list shrinks.
		-->
		<div class="palette__search">
			<label class="palette__search-label" for="widget-palette-search">
				{{ t('portaliq', 'Search widgets') }}
			</label>
			<input
				id="widget-palette-search"
				v-model="query"
				type="search"
				class="palette__search-input"
				data-testid="widget-palette-search"
				:placeholder="t('portaliq', 'For example: zaak, link, formulier')" />
			<p
				class="palette__hits"
				role="status"
				aria-live="polite"
				data-testid="widget-palette-hits">
				{{ hitsText }}
			</p>
		</div>

		<p
			v-if="groups.length === 0"
			class="palette__empty"
			data-testid="widget-palette-empty">
			{{ t('portaliq', 'No widget matches your search.') }}
		</p>

		<section
			v-for="group in groups"
			:key="group.group"
			class="palette__group"
			:data-testid="`widget-palette-group-${group.group}`">
			<h3 class="palette__group-heading">{{ group.label }}</h3>
			<ul class="palette__list">
				<li
					v-for="entry in group.entries"
					:key="entry.key"
					class="palette__item">
					<!--
						DRAG AND KEY ARE THE SAME ACT, and the button is what
						makes that true: it carries the key on a drag, and a
						click or Enter places the widget without a pointer
						(REQ-SNW-002). A div with a drag handler would leave an
						author who cannot point with nothing to press.
					-->
					<button
						type="button"
						class="palette__button"
						:class="{ 'palette__button--warned': !entry.publicSafe }"
						:data-testid="`widget-palette-${entry.key}`"
						:data-public="entry.publicSafe ? 'true' : 'false'"
						draggable="true"
						@click="choose(entry)"
						@dragstart="startDrag($event, entry)">
						<span class="palette__label">{{ entry.label }}</span>
						<span class="palette__key">{{ entry.key }}</span>
						<!--
							The reason travels with the entry rather than
							sitting in a legend somewhere: an author reading one
							row has to be able to tell, from that row, what
							placing it will do.
						-->
						<span v-if="!entry.publicSafe" class="palette__reason">
							{{ entry.reason }}
						</span>
					</button>
				</li>
			</ul>
		</section>

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
import { translate } from '@nextcloud/l10n'
import { NcButton, NcDialog } from '@nextcloud/vue'
import { widgetCatalogue } from '../lib/pageWidgetCatalogue.js'
import { paletteGroups, paletteHitCount } from '../lib/widgetPalette.js'

/**
 * The media type a dragged palette entry carries.
 *
 * Its own type rather than `text/plain`, so a drop that did not come from the
 * palette is not mistaken for one that did.
 *
 * @type {string}
 */
export const PALETTE_DRAG_TYPE = 'application/x-portaliq-widget'

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

		/**
		 * Offer only widgets the public renderer mounts. The portal edit mode
		 * sets it: on the portal a widget that renders as an empty place is
		 * never what the editor meant to add.
		 */
		publicOnly: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:open', 'choose', 'dragging'],

	data() {
		return {
			/** What the author typed in the search field. */
			query: '',
		}
	},

	computed: {
		/**
		 * The catalogue, public entries first.
		 *
		 * @return {Array<object>} The entries.
		 *
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-palette-must-mark-widgets-that-cannot-render-on-a-public-page
		 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-a-page-in-place-on-the-portal-req-pie-006
		 */
		entries() {
			const entries = widgetCatalogue()
			return this.publicOnly
				? entries.filter((entry) => entry.publicSafe)
				: entries
		},

		/**
		 * The entries under their headings, filtered by the search.
		 *
		 * @return {Array<object>} The groups.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-the-palette-must-group-widgets-and-let-an-editor-search-them-req-snw-001
		 */
		groups() {
			return paletteGroups(this.entries, this.query)
		},

		/**
		 * The sentence the live region reads out.
		 *
		 * @return {string} The hit count in words.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-the-palette-must-group-widgets-and-let-an-editor-search-them-req-snw-001
		 */
		hitsText() {
			const hits = paletteHitCount(this.entries, this.query)
			return translate('portaliq', '%n widget found', '%n widgets found', hits)
		},
	},

	methods: {
		/**
		 * Translate. Local rather than the admin app's global mixin, because
		 * the portal edit mode mounts this dialog on the public site too.
		 *
		 * @param {string} app The app id.
		 * @param {string} text The source text.
		 * @param {object} vars The placeholders.
		 * @return {string} The translation.
		 *
		 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-a-page-in-place-on-the-portal-req-pie-006
		 */
		t(app, text, vars) {
			return translate(app, text, vars)
		},

		/**
		 * Hand the chosen key to the designer and close.
		 *
		 * @param {object} entry The catalogue entry.
		 * @return {void}
		 *
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-palette-must-mark-widgets-that-cannot-render-on-a-public-page
		 */
		choose(entry) {
			this.$emit('choose', entry.key)
			this.$emit('update:open', false)
		},

		/**
		 * Carry the key on the drag, so a drop on a grid cell knows what to
		 * place there.
		 *
		 * @param {DragEvent} event The drag.
		 * @param {object} entry The catalogue entry.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-drag-a-widget-from-the-palette-onto-the-grid-req-snw-002
		 */
		startDrag(event, entry) {
			if (!event?.dataTransfer) {
				return
			}

			event.dataTransfer.setData(PALETTE_DRAG_TYPE, entry.key)
			// `copy` rather than `move`: the palette keeps its entry, which is
			// what the cursor should say.
			event.dataTransfer.effectAllowed = 'copy'
			this.$emit('dragging', entry.key)
		},
	},
}
</script>

<style scoped>
.palette__intro {
	margin-bottom: 12px;
	color: var(--color-text-maxcontrast);
}

.palette__search {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;
}

.palette__search-label {
	font-weight: bold;
}

.palette__search-input {
	width: 100%;
}

.palette__hits {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.palette__empty {
	color: var(--color-text-maxcontrast);
}

.palette__group-heading {
	margin: 12px 0 6px;
	font-size: 1em;
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
	text-align: start;
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
