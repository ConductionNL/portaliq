<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  TrafficRecordingPlayer — replays one masked session recording
  (portal-traffic-experiments) in a sandboxed frame.

  WHAT THE FRAME IS. The recorded snapshot is rebuilt as HTML from the
  masked tree: every text node becomes a run of block characters of its
  length, every input a run of dots, every image a grey box. That HTML
  goes into an iframe as `srcdoc` with `sandbox="allow-same-origin"` and
  NOTHING else: no `allow-scripts`, so no script runs whatever the tree
  held (and the tree holds none: the mask drops them), no forms, no
  navigation. `allow-same-origin` is there so this component can scroll
  the frame and the recorded stylesheet links of the portal's own origin
  load; it grants a document that cannot run code nothing else.

  The pointer and the clicks are drawn by this component OVER the frame,
  scaled from the recorded viewport to the frame's size.

  @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
-->
<template>
	<NcModal
		v-if="open"
		:name="title"
		size="large"
		data-testid="traffic-recording-player"
		@close="$emit('update:open', false)">
		<div class="recording-player">
			<div class="recording-player__toolbar">
				<NcButton
					:aria-label="
						playing ? t('portaliq', 'Pause') : t('portaliq', 'Play')
					"
					data-testid="traffic-recording-toggle"
					@click="toggle">
					{{ playing ? t('portaliq', 'Pause') : t('portaliq', 'Play') }}
				</NcButton>
				<NcButton data-testid="traffic-recording-restart" @click="restart">
					{{ t('portaliq', 'Restart') }}
				</NcButton>
				<span
					class="recording-player__time"
					data-testid="traffic-recording-time">
					{{ clock(position) }} / {{ clock(duration) }}
				</span>
				<span
					class="recording-player__page"
					data-testid="traffic-recording-page"
					>{{ page }}</span
				>
			</div>
			<p class="recording-player__note">
				{{
					t(
						'portaliq',
						'Every text and every typed value was masked to its length before it left the browser; the blocks show where text was, not what it said.',
					)
				}}
			</p>
			<div
				ref="stage"
				class="recording-player__stage"
				:style="{ height: stageHeight + 'px' }">
				<iframe
					ref="frame"
					class="recording-player__frame"
					sandbox="allow-same-origin"
					:srcdoc="srcdoc"
					:title="t('portaliq', 'Recorded page, masked')"
					:style="frameStyle"
					data-testid="traffic-recording-frame" />
				<span
					class="recording-player__cursor"
					:class="{ 'recording-player__cursor--click': clicked }"
					:style="{
						left: cursor.x * scale + 'px',
						top: cursor.y * scale + 'px',
					}"
					aria-hidden="true" />
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'

/**
 * Elements written without a closing tag.
 */
const VOID = [
	'area',
	'base',
	'br',
	'col',
	'embed',
	'hr',
	'img',
	'input',
	'link',
	'meta',
	'source',
	'track',
	'wbr',
]

/**
 * Elements the mask reduced to a box.
 */
const BOXES = [
	'img',
	'video',
	'audio',
	'iframe',
	'canvas',
	'object',
	'embed',
	'picture',
]

/**
 * The longest gap the replay waits between two events, in milliseconds.
 */
const MAX_GAP = 1000

/**
 * The style every replayed document gets: the masks, the boxes, and no
 * pointer events.
 */
const MASK_STYLE =
	'.pq-mask{color:rgba(127,127,127,.55);letter-spacing:-.05em;word-break:break-all}'
	+ '.pq-box{display:inline-block;background:rgba(127,127,127,.25);min-width:1em;min-height:1em}'
	+ 'input,textarea,select{color:rgba(127,127,127,.7)}'

export default {
	name: 'TrafficRecordingPlayer',

	components: {
		NcButton,
		NcModal,
	},

	props: {
		/** Whether the player is open. */
		open: {
			type: Boolean,
			default: false,
		},

		/** The portalTrafficRecording object. */
		recording: {
			type: Object,
			default: null,
		},
	},

	emits: ['update:open'],

	data() {
		return {
			index: 0,
			playing: false,
			timer: null,
			cursor: { x: 0, y: 0 },
			clicked: false,
			srcdoc: '',
			viewport: { w: 1280, h: 800 },
			scale: 1,
			stageWidth: 800,
			page: '',
			// The stylesheets the stream carried so far, by hash: a sheet
			// travels once per visit and every snapshot refers to it.
			styles: {},
		}
	},

	computed: {
		/**
		 * The modal's heading.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
		 * @return {string} The title.
		 */
		title() {
			const started = this.recording
				? String(this.recording.startedAt || '')
				: ''
			return this.t('portaliq', 'Recording of {when}', {
				when: started.replace('T', ' ').substring(0, 19),
			})
		},

		/**
		 * Every event of every chunk, in time order.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
		 * @return {Array<object>} The events.
		 */
		events() {
			const chunks =
				this.recording && Array.isArray(this.recording.chunks)
					? this.recording.chunks
					: []
			const out = []
			chunks.forEach((chunk) => {
				;(Array.isArray(chunk.events) ? chunk.events : []).forEach(
					(event) => {
						if (event && typeof event.k === 'string') {
							out.push(event)
						}
					},
				)
			})
			return out.sort((a, b) => (Number(a.t) || 0) - (Number(b.t) || 0))
		},

		/**
		 * The recording's length in milliseconds: the last event's offset.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
		 * @return {number} The length.
		 */
		duration() {
			const last = this.events[this.events.length - 1]
			return last ? Number(last.t) || 0 : 0
		},

		/**
		 * Where the replay is, in milliseconds.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
		 * @return {number} The offset.
		 */
		position() {
			const event = this.events[Math.min(this.index, this.events.length) - 1]
			return event ? Number(event.t) || 0 : 0
		},

		/**
		 * The frame's size and scale, from the recorded viewport.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
		 * @return {object} The style.
		 */
		frameStyle() {
			return {
				width: this.viewport.w + 'px',
				height: this.viewport.h + 'px',
				transform: 'scale(' + this.scale + ')',
			}
		},

		/**
		 * The stage's height: the scaled viewport.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
		 * @return {number} Pixels.
		 */
		stageHeight() {
			return Math.round(this.viewport.h * this.scale)
		},
	},

	watch: {
		/**
		 * Start the replay when the player opens, stop it when it closes.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
		 * @param {boolean} value Whether the player is open.
		 * @return {void}
		 */
		open(value) {
			if (value) {
				this.$nextTick(() => this.restart())
				return
			}
			this.pause()
		},
	},

	beforeUnmount() {
		this.pause()
	},

	methods: {
		/**
		 * Start from the first event and play.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
		 * @return {void}
		 */
		restart() {
			this.pause()
			this.index = 0
			this.srcdoc = ''
			this.page = ''
			this.cursor = { x: 0, y: 0 }
			this.styles = {}
			this.measure()
			this.playing = true
			this.step()
		},

		/**
		 * Play or pause.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
		 * @return {void}
		 */
		toggle() {
			if (this.playing) {
				this.pause()
				return
			}
			if (this.index >= this.events.length) {
				this.restart()
				return
			}
			this.playing = true
			this.step()
		},

		/**
		 * Stop the clock.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
		 * @return {void}
		 */
		pause() {
			this.playing = false
			if (this.timer !== null) {
				window.clearTimeout(this.timer)
				this.timer = null
			}
		},

		/**
		 * The stage's width, for the scale.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
		 * @return {void}
		 */
		measure() {
			const stage = this.$refs.stage
			this.stageWidth =
				stage && stage.clientWidth > 0 ? stage.clientWidth : 800
			this.scale = Math.min(1, this.stageWidth / this.viewport.w)
		},

		/**
		 * Apply the next event and schedule the one after it by the
		 * recorded gap, capped so a long pause does not stall the replay.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
		 * @return {void}
		 */
		step() {
			if (!this.playing || this.index >= this.events.length) {
				this.playing = false
				return
			}
			const event = this.events[this.index]
			this.apply(event)
			this.index += 1
			const next = this.events[this.index]
			if (!next) {
				this.playing = false
				return
			}
			const gap = Math.min(
				MAX_GAP,
				Math.max(0, (Number(next.t) || 0) - (Number(event.t) || 0)),
			)
			this.timer = window.setTimeout(() => {
				this.timer = null
				this.step()
			}, gap)
		},

		/**
		 * Apply one event to the frame and the overlay.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
		 * @param {object} event The event.
		 * @return {void}
		 */
		apply(event) {
			const frame = this.$refs.frame
			switch (event.k) {
				case 'y':
					if (typeof event.h === 'string' && event.h !== '') {
						this.styles[event.h] = String(event.s || '')
					}
					break
				case 's':
					this.viewport = {
						w: Number(event.w) || this.viewport.w,
						h: Number(event.h) || this.viewport.h,
					}
					this.measure()
					this.srcdoc = this.document(event.n)
					break
				case 'v':
					this.viewport = {
						w: Number(event.w) || this.viewport.w,
						h: Number(event.h) || this.viewport.h,
					}
					this.measure()
					break
				case 'm':
					this.cursor = {
						x: Number(event.x) || 0,
						y: Number(event.y) || 0,
					}
					break
				case 'c':
					this.cursor = {
						x: Number(event.x) || 0,
						y: Number(event.y) || 0,
					}
					this.clicked = true
					window.setTimeout(() => {
						this.clicked = false
					}, 300)
					break
				case 'r':
					if (frame && frame.contentWindow) {
						try {
							frame.contentWindow.scrollTo(
								Number(event.x) || 0,
								Number(event.y) || 0,
							)
						} catch {
							// A frame that is still loading cannot scroll yet.
						}
					}
					break
				case 'n':
					this.page = String(event.p || '')
					break
				default:
					break
			}
		},

		/**
		 * A masked tree as a whole document.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
		 * @param {object} root The snapshot's root node.
		 * @return {string} The HTML.
		 */
		document(root) {
			return (
				'<!doctype html><style>' + MASK_STYLE + '</style>' + this.html(root)
			)
		},

		/**
		 * One masked node as HTML: a text node as a run of blocks of its
		 * length, an element with its allowed attributes and children.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
		 * @param {object} node The node.
		 * @return {string} The HTML.
		 */
		html(node) {
			if (!node || typeof node !== 'object') {
				return ''
			}
			if (typeof node.n !== 'string') {
				const length = Math.min(400, Math.max(0, Number(node.l) || 0))
				return length === 0
					? ''
					: '<span class="pq-mask">' + '░'.repeat(length) + '</span>'
			}
			const tag = node.n.replace(/[^a-z0-9-]/g, '')
			if (tag === '' || tag === 'script') {
				return ''
			}
			let attributes = ''
			const given = node.a && typeof node.a === 'object' ? node.a : {}
			Object.keys(given).forEach((name) => {
				if (/^[a-zA-Z-]+$/.test(name) && !/^on/i.test(name)) {
					attributes +=
						' ' + name + '="' + this.escape(String(given[name])) + '"'
				}
			})
			if (BOXES.includes(tag)) {
				attributes +=
					' class="pq-box ' + this.escape(String(given.class || '')) + '"'
			}
			if (typeof node.v === 'number' && node.v > 0) {
				attributes += ' value="' + '•'.repeat(Math.min(200, node.v)) + '"'
			}
			if (VOID.includes(tag)) {
				return '<' + tag + attributes + '>'
			}
			let inner = ''
			if (tag === 'style') {
				const sheet =
					typeof node.s === 'string'
						? node.s
						: this.styles[String(node.h || '')] || ''
				inner = sheet.replace(/<\/style/gi, '')
			} else {
				;(Array.isArray(node.c) ? node.c : []).forEach((child) => {
					inner += this.html(child)
				})
			}
			return '<' + tag + attributes + '>' + inner + '</' + tag + '>'
		},

		/**
		 * Escape an attribute value.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
		 * @param {string} value The value.
		 * @return {string} The escaped value.
		 */
		escape(value) {
			return value
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
		},

		/**
		 * Milliseconds as m:ss.
		 *
		 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
		 * @param {number} ms The milliseconds.
		 * @return {string} The clock.
		 */
		clock(ms) {
			const seconds = Math.max(0, Math.round((Number(ms) || 0) / 1000))
			return (
				Math.floor(seconds / 60)
				+ ':'
				+ String(seconds % 60).padStart(2, '0')
			)
		},
	},
}
</script>

<style scoped>
.recording-player {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 16px;
}

.recording-player__toolbar {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}

.recording-player__time {
	font-variant-numeric: tabular-nums;
}

.recording-player__page {
	color: var(--color-text-maxcontrast);
	word-break: break-all;
}

.recording-player__note {
	color: var(--color-text-maxcontrast);
}

.recording-player__stage {
	position: relative;
	width: 100%;
	overflow: hidden;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.recording-player__frame {
	position: absolute;
	top: 0;
	inset-inline-start: 0;
	border: 0;
	background: #fff;
	transform-origin: 0 0;
	pointer-events: none;
}

.recording-player__cursor {
	position: absolute;
	width: 14px;
	height: 14px;
	margin: -7px 0 0 -7px;
	border: 2px solid var(--color-primary-element);
	border-radius: 50%;
	background: rgba(0, 130, 201, 0.3);
	transition:
		inset-inline-start 0.05s linear,
		top 0.05s linear;
	pointer-events: none;
}

.recording-player__cursor--click {
	background: var(--color-error);
	transform: scale(1.6);
}

@media (prefers-reduced-motion: reduce) {
	.recording-player__cursor {
		transition: none;
	}
}
</style>
