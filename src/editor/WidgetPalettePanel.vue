<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  WidgetPalettePanel — the page designer's widget palette, beside the canvas.

  🔴 IT WAS A MODAL DIALOG AND THAT MADE ITS OWN DROP TARGET UNREACHABLE. As an
  `aria-modal` NcDialog it painted a full-screen backdrop, so while it was open
  the canvas behind it took no pointer at ANY coordinate: a live run on 4 Oct
  2026 caught the dialog's own header intercepting the drop over
  `designer-canvas`, after 164 retries. The tiles already carried
  `draggable="true"` and the canvas already carried `@drop`; the gesture was
  wired and walled off. REQ-SNW-002 says "drag and key are the same act", and
  only the key half held.

  So this is a NON-MODAL PANEL: no backdrop, no focus trap, and the grid stays
  visible and reachable while it is open. It is a labelled region rather than a
  dialog, because `role="dialog"` without `aria-modal` buys an author nothing
  and invites the trap back. The button that opens it carries `aria-expanded`
  and `aria-controls`, which is the disclosure pattern a screen reader already
  knows.

  WHAT IT STILL OWES THE KEYBOARD, since a non-modal panel traps nothing:
  opening it moves focus to the search field, Escape closes it, and closing it
  returns focus to the control that opened it. Without that last step an author
  who presses Escape is left with focus nowhere.

  THE WHOLE CATALOGUE IS OFFERED, and the entries a published page will not
  mount are marked rather than removed. See src/lib/pageWidgetCatalogue.js for
  why marking beats hiding.

  @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-drag-a-widget-from-the-palette-onto-the-grid-req-snw-002
  @spec openspec/specs/portal-page-designer/spec.md#requirement-the-palette-must-mark-widgets-that-cannot-render-on-a-public-page
-->
<template>
	<aside
		v-if="open"
		id="widget-palette"
		class="palette"
		data-testid="widget-palette"
		role="region"
		:aria-label="t('portaliq', 'Add a widget')"
		@keydown.esc.stop="close">
		<header class="palette__bar">
			<h3 class="palette__title">{{ t('portaliq', 'Add a widget') }}</h3>
			<NcButton data-testid="widget-palette-close" @click="close">
				{{ t('portaliq', 'Close') }}
			</NcButton>
		</header>

		<p class="palette__intro">
			{{
				t(
					'portaliq',
					'Pick a widget, or drag one onto the page. You can move and resize it afterwards.',
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
				ref="search"
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
			<h4 class="palette__group-heading">{{ group.label }}</h4>
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

						⚠️ THE TILE TESTID CARRIES ITS OWN `tile-` SEGMENT. A
						widget key comes from the registry, so that part of the
						name is unbounded, and it used to sit in the same
						namespace as this panel's fixed ids: a widget keyed
						`search`, `hits`, `empty` or `close` collided with the
						search box, the hit count, the empty state or the close
						button. The `search` widget really does exist, and the
						e2e read two elements for one id.
					-->
					<button
						type="button"
						class="palette__button"
						:class="{ 'palette__button--warned': !entry.publicSafe }"
						:data-testid="`widget-palette-tile-${entry.key}`"
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
	</aside>
</template>

<script>
import { translate, translatePlural } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import { widgetCatalogue } from '../lib/pageWidgetCatalogue.js'
import { paletteGroups, paletteHitCount } from '../lib/widgetPalette.js'
import { PALETTE_DRAG_TYPE } from './paletteDrag.js'

export default {
	name: 'WidgetPalettePanel',

	components: {
		NcButton,
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

			/**
			 * What had focus when the panel opened, to give it back on close.
			 *
			 * @type {HTMLElement|null}
			 */
			opener: null,
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
			// translatePlural, NOT translate. `translate(app, text, vars, count)`
			// takes the THIRD argument as vars, so passing the plural there
			// handed it an ignored string and the count never chose a form: the
			// palette read "2 widget found" for every number above one. It did
			// substitute %n, which is why it looked translated.
			return translatePlural(
				'portaliq',
				'%n widget found',
				'%n widgets found',
				hits,
			)
		},
	},

	watch: {
		open: {
			immediate: true,
			/**
			 * Move focus with the panel: into it on open, back to the opener on
			 * close. `was` is checked so the immediate first run, which arrives
			 * with no previous value on a closed panel, does not pull focus out
			 * of whatever the author was already using.
			 *
			 * @param {boolean} open Whether the panel is now open.
			 * @param {boolean} was Whether it was open before.
			 * @return {void}
			 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-the-palette-must-not-cover-the-grid-it-drops-onto-req-snw-004
			 */
			handler(open, was) {
				if (open) {
					this.takeFocus()
					return
				}
				if (was) {
					this.giveFocusBack()
				}
			},
		},
	},

	methods: {
		/**
		 * Translate. Local rather than the admin app's global mixin, because
		 * the portal edit mode mounts this panel on the public site too.
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
		 * Remember the control that opened the panel and focus the search.
		 *
		 * A non-modal panel traps nothing, so this is the whole of its focus
		 * handling: an author who opens it lands in the field they are going to
		 * type in, and tabbing on walks out of the panel into the page.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-drag-a-widget-from-the-palette-onto-the-grid-req-snw-002
		 */
		takeFocus() {
			const active =
				typeof document === 'undefined' ? null : document.activeElement
			this.opener = active && active !== document.body ? active : null
			this.$nextTick(() => this.$refs.search?.focus?.())
		},

		/**
		 * Put focus back where it was before the panel opened.
		 *
		 * Only if that element is still on the page: the control that opened the
		 * panel can have been re-rendered since, and focusing a detached node
		 * sends focus to the document instead, which is worse than leaving it.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-drag-a-widget-from-the-palette-onto-the-grid-req-snw-002
		 */
		giveFocusBack() {
			const opener = this.opener
			this.opener = null
			if (opener?.isConnected && typeof opener.focus === 'function') {
				opener.focus()
			}
		},

		/**
		 * Close the panel.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-drag-a-widget-from-the-palette-onto-the-grid-req-snw-002
		 */
		close() {
			this.$emit('update:open', false)
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
			this.close()
		},

		/**
		 * Carry the key on the drag, so a drop on a grid cell knows what to
		 * place there.
		 *
		 * THE PANEL STAYS OPEN. An author who drags one widget usually drags
		 * the next one too, and there is no backdrop left to get in the way.
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
/*
 * A COLUMN BESIDE THE CANVAS, not over it. It is a flex item of the editor's
 * pane row (PageGridEditor.vue), and it sticks so an author scrolling a long
 * page keeps the palette in view while the drop target scrolls past.
 *
 * The width is the inspector's width on the other side, so the canvas sits
 * between two equal columns rather than off-centre.
 */
.palette {
	flex: 0 0 320px;
	max-width: 100%;
	align-self: flex-start;
	position: sticky;
	top: 8px;
	max-height: calc(100vh - 120px);
	overflow-y: auto;
	padding-inline-end: 16px;
	border-inline-end: 1px solid var(--color-border);
}

.palette__bar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
}

.palette__title {
	margin: 0;
	font-size: 1em;
}

.palette__intro {
	margin: 8px 0 12px;
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

/*
 * ONE TILE PER ROW. In a 320px column a two-column grid gives tiles too narrow
 * to read a Dutch label in, and the panel scrolls rather than the list, so the
 * group headings scroll with the tiles they head.
 */
.palette__list {
	display: grid;
	grid-template-columns: 1fr;
	gap: 8px;
	list-style: none;
	margin: 0;
	padding: 0;
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
	color: var(--color-main-text);
	cursor: grab;
	/* A drag that starts by selecting the label's text is a drag that does
	   not start. */
	user-select: none;
}

.palette__button:hover {
	background: var(--color-background-hover);
}

/*
 * ITS OWN RING, not the hover tint. A background change alone is not a focus
 * indicator: it fails against the tile beside it and disappears where the
 * portal's theme paints its own surfaces (WCAG 2.2 AA, 2.4.11).
 */
.palette__button:focus-visible,
.palette__search-input:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
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

/*
 * NARROW: the editor's panes stack, so the palette becomes a band above the
 * canvas. It stops sticking there, because a sticky band would cover the grid
 * it is dropping onto.
 */
@media (max-width: 1024px) {
	.palette {
		flex-basis: auto;
		width: 100%;
		position: static;
		max-height: 50vh;
		padding-inline-end: 0;
		border-inline-end: none;
		border-bottom: 1px solid var(--color-border);
		padding-bottom: 16px;
	}
}
</style>
