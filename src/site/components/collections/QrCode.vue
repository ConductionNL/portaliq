<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A QR code drawn in the browser as an inline SVG (link-field-qr-code). Nothing
	is sent to a QR service: an offer link is a one-time claim on a certificate.
	Black modules on a white field with a four-module quiet zone, whatever the
	theme, because a scanner needs dark on light. It draws nothing when the
	value does not encode.
-->
<template>
	<svg
		v-if="symbol"
		class="pq-qr"
		role="img"
		:aria-label="name"
		:viewBox="`0 0 ${symbol.size + 8} ${symbol.size + 8}`"
		:width="size"
		:height="size"
		shape-rendering="crispEdges"
		data-testid="qr-code">
		<rect :width="symbol.size + 8" :height="symbol.size + 8" fill="#fff" />
		<path :d="path" fill="#000" />
	</svg>
</template>

<script>
import { encodeQr } from '../../lib/qr.js'

/**
 * @spec openspec/changes/link-field-qr-code/tasks.md#t4
 */
export default {
	name: 'QrCode',

	props: {
		/** The text the code says: an address. */
		value: { type: String, default: '' },
		/** What the code opens, for its accessible name. */
		label: { type: String, default: '' },
		/** The words of the accessible name, with `{label}`. */
		nameTemplate: { type: String, default: 'QR code for: {label}' },
		/** The width and height in CSS pixels. */
		size: { type: Number, default: 160 },
	},

	computed: {
		/**
		 * @return {{size: number, modules: Array<Array<boolean>>}|null} The symbol, or null when the value does not encode.
		 * @spec openspec/changes/link-field-qr-code/tasks.md#t4
		 */
		symbol() {
			return encodeQr(this.value)
		},

		/**
		 * @return {string} The accessible name.
		 * @spec openspec/changes/link-field-qr-code/tasks.md#t4
		 */
		name() {
			return this.nameTemplate.replace('{label}', this.label)
		},

		/**
		 * @return {string} One path for all dark modules, a run of modules per segment.
		 * @spec openspec/changes/link-field-qr-code/tasks.md#t4
		 */
		path() {
			const parts = []
			this.symbol.modules.forEach((row, r) => {
				let c = 0
				while (c < row.length) {
					if (!row[c]) {
						c++
						continue
					}
					const from = c
					while (c < row.length && row[c]) {
						c++
					}
					parts.push(`M${from + 4} ${r + 4}h${c - from}v1h-${c - from}z`)
				}
			})
			return parts.join('')
		},
	},
}
</script>

<style scoped>
.pq-qr {
	display: block;
	max-inline-size: 100%;
	block-size: auto;
}
</style>
