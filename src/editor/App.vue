<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div class="pq-editor" data-testid="editor-root">
		<header class="pq-editor__bar">
			<span class="pq-editor__title">
				{{ site.title || 'Portaal' }} — {{ route }}
			</span>

			<!--
				THE BREAKPOINT SWITCHER (task 4.5).

				It resizes the CANVAS, not the window, and the canvas is a real
				element carrying the real stylesheets — so the same media
				queries and the same grid reflow it that reflow the public page.
				A switcher that swapped in a "mobile preview" component would be
				showing an approximation, which is the one thing a breakpoint
				preview must not do.
			-->
			<div class="pq-editor__breakpoints" role="group" aria-label="Schermbreedte">
				<button
					v-for="option in breakpoints"
					:key="option.width"
					type="button"
					:aria-pressed="String(width === option.width)"
					:data-testid="`breakpoint-${option.width}`"
					@click="width = option.width">
					{{ option.label }}
				</button>
			</div>

			<div class="pq-editor__actions">
				<button
					type="button"
					:disabled="!undoable"
					data-testid="editor-undo"
					@click="stepBack">
					Ongedaan maken
				</button>
				<button
					type="button"
					:disabled="!redoable"
					data-testid="editor-redo"
					@click="stepForward">
					Opnieuw
				</button>
				<button
					type="button"
					class="pq-editor__save"
					:disabled="saving"
					data-testid="editor-save"
					@click="save">
					{{ saving ? 'Bezig…' : 'Opslaan' }}
				</button>
			</div>
		</header>

		<!--
			THE GUARDRAILS, WHERE THE AUTHOR IS WORKING (tasks 5.1, 5.2).

			Measured on the canvas after every change, by the same functions the
			CI check uses. Reporting the measured ratio and the exact heading
			jump rather than a count is the difference between a warning
			somebody can act on and one they learn to dismiss.
		-->
		<p
			v-for="(warning, i) in warnings"
			:key="i"
			class="pq-editor__warning"
			role="status"
			data-testid="editor-warning">
			{{ warning }}
		</p>

		<div class="pq-editor__body">
			<div class="pq-editor__side">
				<BlockLibrary :region="selectedRegion" @insert="onInsert" />
				<LayerTree
					:blocks="regions"
					:selection="selection"
					@select="onSelect"
					@move="onMove" />
			</div>

			<div class="pq-editor__canvas">
				<EditorCanvas
					ref="canvas"
					:regions="regions"
					:site="site"
					:menus="menus"
					:glossary="glossary"
					:contributions="contributions"
					:width="width"
					:selection="selection"
					:pageTitle="(page && page.title) || ''"
					:route="route"
					:authBase="authBase"
					@select="onSelect" />
			</div>

			<div class="pq-editor__side">
				<BlockInspector
					:block="selectedBlock"
					@change="onFieldChange"
					@remove="onRemove" />
			</div>
		</div>
	</div>
</template>

<script>
import BlockInspector from './components/BlockInspector.vue'
import BlockLibrary from './components/BlockLibrary.vue'
import EditorCanvas from './components/EditorCanvas.vue'
import LayerTree from './components/LayerTree.vue'
import { auditContrast } from '../site/lib/contrast.js'
import { emptyRegions, resolveRegions } from '../site/lib/regions.js'
import {
	canRedo,
	canUndo,
	createHistory,
	record,
	redo,
	undo,
} from './lib/history.js'
import {
	insertBlock,
	moveBlock,
	removeBlock,
	setField,
} from './lib/operations.js'

/**
 * The page editor.
 *
 * IT EDITS REGIONS, which is the whole reason the region model came first: the
 * thing an author drags around and the thing the renderer reads are the same
 * structure, so there is no translation step to get wrong.
 *
 * @spec openspec/changes/portal-page-composition/tasks.md
 */
export default {
	name: 'EditorApp',

	components: { BlockInspector, BlockLibrary, EditorCanvas, LayerTree },

	props: {
		/** The portal slug being edited. */
		portalSlug: {
			type: String,
			default: '',
		},

		/** The route being edited. */
		route: {
			type: String,
			default: '/',
		},
	},

	data() {
		return {
			site: {},
			menus: [],
			glossary: [],
			contributions: [],
			page: null,
			history: createHistory(emptyRegions()),
			selection: null,
			width: 1440,
			saving: false,
			warnings: [],
			breakpoints: [
				{ width: 1440, label: 'Desktop' },
				{ width: 768, label: 'Tablet' },
				{ width: 390, label: 'Mobiel' },
			],
		}
	},

	computed: {
		/**
		 * The portal auth/API base, derived the way the renderer derives it.
		 *
		 * @return {string} The base.
		 */
		authBase() {
			return this.apiBase().replace(/\/api\/content\/?$/, '/portal/api')
		},

		/**
		 * @return {object} The regions currently being edited.
		 */
		regions() {
			return this.history.present
		},

		/**
		 * @return {boolean} Whether undo would do anything.
		 */
		undoable() {
			return canUndo(this.history)
		},

		/**
		 * @return {boolean} Whether redo would do anything.
		 */
		redoable() {
			return canRedo(this.history)
		},

		/**
		 * @return {string} The region the selection is in.
		 */
		selectedRegion() {
			return this.selection ? this.selection.region : 'main'
		},

		/**
		 * @return {object|null} The selected block.
		 */
		selectedBlock() {
			if (!this.selection || this.selection.index < 0) {
				return null
			}

			const list = this.regions[this.selection.region] || []
			return list[this.selection.index] || null
		},
	},

	watch: {
		/**
		 * Re-audit whenever the page changes.
		 *
		 * @return {void}
		 */
		regions: {
			deep: true,
			handler() {
				this.$nextTick(() => this.audit())
			},
		},
	},

	/**
	 * Load the page, then start a history around it.
	 *
	 * @return {Promise<void>} Resolves once the page is on the canvas.
	 */
	async mounted() {
		await this.load()
		window.addEventListener('keydown', this.onKey)
	},

	beforeUnmount() {
		window.removeEventListener('keydown', this.onKey)
	},

	methods: {
		/**
		 * The content API base, from the runtime configuration.
		 *
		 * @return {string} The base.
		 */
		apiBase() {
			const tag = document.getElementById('portaliq-editor-config')
			try {
				const config = JSON.parse((tag && tag.textContent) || '{}')
				return String(config.apiBase || '/index.php/apps/portaliq/api/content')
			} catch {
				return '/index.php/apps/portaliq/api/content'
			}
		},

		/**
		 * The CSRF token the template emitted.
		 *
		 * @return {string} The token, or ''.
		 */
		requestToken() {
			const tag = document.getElementById('portaliq-editor-config')
			try {
				return String(JSON.parse((tag && tag.textContent) || '{}').requestToken || '')
			} catch {
				return ''
			}
		},

		/**
		 * Fetch the portal and the page being edited.
		 *
		 * READ OVER THE SAME PUBLIC CONTRACT the renderer uses. An editor
		 * reading a private shape would be editing something no visitor sees.
		 *
		 * @return {Promise<void>} Resolves when loaded.
		 */
		async load() {
			const base = this.apiBase()
			const portal = this.portalSlug ? `&portal=${encodeURIComponent(this.portalSlug)}` : ''

			const [site, menus, glossary, page] = await Promise.all([
				fetch(`${base}/site?x=1${portal}`).then((r) => r.json()),
				fetch(`${base}/menus?x=1${portal}`).then((r) => r.json()),
				fetch(`${base}/glossary?x=1${portal}`).then((r) => r.json()),
				fetch(`${base}/page?route=${encodeURIComponent(this.route)}${portal}`)
					.then((r) => (r.ok ? r.json() : null))
					.catch(() => null),
			])

			this.site = site || {}
			this.menus = (menus && menus.menus) || []
			this.glossary = (glossary && glossary.terms) || []
			this.page = page

			try {
				const contributions = await fetch(`${base}/contributions?x=1${portal}`).then((r) => r.json())
				this.contributions = (contributions && contributions.contributions) || []
			} catch {
				this.contributions = []
			}

			this.history = createHistory(
				resolveRegions(
					(page && page.body && page.body.regions) || {},
					this.site.regions || {},
				),
			)
			this.$nextTick(() => this.audit())
		},

		/**
		 * Apply an edit and record it.
		 *
		 * ONE PLACE, so every operation is undoable. An edit that bypassed this
		 * would be invisible to the history and would silently break undo for
		 * everything after it.
		 *
		 * @param {object} next The next regions.
		 * @return {void}
		 */
		commit(next) {
			this.history = record(this.history, next)
		},

		/**
		 * @param {object} payload `{region, key}`.
		 * @return {void}
		 */
		onInsert({ region, key }) {
			this.commit(insertBlock(this.regions, region, key))
			this.selection = { region, index: (this.regions[region] || []).length - 1 }
		},

		/**
		 * @param {object} payload `{region, index}`.
		 * @return {void}
		 */
		onSelect(payload) {
			this.selection = payload
		},

		/**
		 * @param {object} payload `{region, index, to}`.
		 * @return {void}
		 */
		onMove({ region, index, to }) {
			this.commit(moveBlock(this.regions, region, index, region, to))
			this.selection = { region, index: to }
		},

		/**
		 * @return {void}
		 */
		onRemove() {
			if (!this.selection) {
				return
			}

			this.commit(removeBlock(this.regions, this.selection.region, this.selection.index))
			this.selection = null
		},

		/**
		 * @param {object} payload `{name, value}`.
		 * @return {void}
		 */
		onFieldChange({ name, value }) {
			if (!this.selection) {
				return
			}

			this.commit(
				setField(this.regions, this.selection.region, this.selection.index, name, value),
			)
		},

		/**
		 * @return {void}
		 */
		stepBack() {
			this.history = undo(this.history)
			this.selection = null
		},

		/**
		 * @return {void}
		 */
		stepForward() {
			this.history = redo(this.history)
			this.selection = null
		},

		/**
		 * Keyboard undo/redo.
		 *
		 * @param {KeyboardEvent} event The key.
		 * @return {void}
		 */
		onKey(event) {
			if ((event.ctrlKey || event.metaKey) === false) {
				return
			}

			if (event.key === 'z' && event.shiftKey === false) {
				event.preventDefault()
				this.stepBack()
			}

			if (event.key === 'y' || (event.key === 'z' && event.shiftKey)) {
				event.preventDefault()
				this.stepForward()
			}
		},

		/**
		 * Measure the canvas and report what an author can act on.
		 *
		 * THE SAME FUNCTIONS THE CI CHECK USES. A guardrail that measured
		 * differently from the gate would let an author fix a warning here and
		 * still fail the build — or worse, pass here and ship the defect.
		 *
		 * @return {void}
		 */
		audit() {
			const canvas = this.$refs.canvas && this.$refs.canvas.$el
			if (!canvas) {
				this.warnings = []
				return
			}

			const warnings = []

			const { failures } = auditContrast(canvas)
			for (const failure of failures.slice(0, 5)) {
				warnings.push(
					`Contrast ${failure.ratio}:1 — "${failure.text}" is moeilijk leesbaar op deze achtergrond (AA vraagt 4.5).`,
				)
			}

			// A SKIPPED HEADING LEVEL, named rather than counted (task 5.1).
			const outline = [...canvas.querySelectorAll('h1,h2,h3,h4,h5,h6')]
				.filter((h) => h.getClientRects().length > 0)
				.map((h) => ({ level: Number(h.tagName.slice(1)), text: (h.textContent || '').trim().slice(0, 40) }))

			for (let i = 1; i < outline.length; i++) {
				if (outline[i].level - outline[i - 1].level > 1) {
					warnings.push(
						`Kopniveau overgeslagen: h${outline[i - 1].level} → h${outline[i].level} bij "${outline[i].text}".`,
					)
				}
			}

			this.warnings = warnings
		},

		/**
		 * The regions this page OVERRIDES, and only those.
		 *
		 * A PAGE MUST NOT FREEZE WHAT IT INHERITED. Saving every region as the
		 * editor resolved it writes the portal's header and footer into the
		 * page itself — measured on the first working save, which stored
		 * `brandHeader@header` and `footerColumns@footer` into a page that had
		 * never mentioned either. From then on that page would keep its copy of
		 * the shell forever, and changing the portal's header would leave every
		 * previously-edited page behind.
		 *
		 * So a region identical to what it inherited is OMITTED, which is what
		 * the three-state model means by "absent". A region the author actually
		 * emptied is still sent as `[]` — the state that has to survive.
		 *
		 * @return {object} The regions to store.
		 */
		changedRegions() {
			const inherited = resolveRegions({}, this.site.regions || {})
			const changed = {}

			for (const [region, blocks] of Object.entries(this.regions)) {
				if (JSON.stringify(blocks) !== JSON.stringify(inherited[region] || [])) {
					changed[region] = blocks
				}
			}

			return changed
		},

		/**
		 * Save the page's regions.
		 *
		 * @return {Promise<void>} Resolves when saved.
		 */
		async save() {
			this.saving = true
			try {
				const response = await fetch(
					`/index.php/apps/portaliq/api/pages/regions?route=${encodeURIComponent(this.route)}&portal=${encodeURIComponent(this.portalSlug)}`,
					{
						method: 'PUT',
						headers: {
							'Content-Type': 'application/json',
							// FROM THE CONFIG BLOCK, not from `window.OC`: this
							// document is RENDER_AS_BLANK and has no core JS.
							requesttoken: this.requestToken(),
						},
						body: JSON.stringify({ regions: this.changedRegions() }),
					},
				)

				if (response.ok === false) {
					this.warnings = [`Opslaan is niet gelukt (${response.status}).`]
				}
			} catch (error) {
				this.warnings = [`Opslaan is niet gelukt: ${error.message}`]
			} finally {
				this.saving = false
			}
		},
	},
}
</script>
