<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A `qr` field (link-field-qr-code): the link in its own words, the code, the
	address as plain text and a caption. On the record page the code is always
	there; in a table cell it opens on request, one at a time, so ten
	certificates do not become ten codes. A value that is no address (a refused
	scheme, `javascript:`) reads as plain text: no code, no link.
-->
<template>
	<span v-if="address === ''" class="pq-qr-value" data-testid="qr-text">{{
		value
	}}</span>
	<span v-else class="pq-qr-value" data-testid="qr-value">
		<a class="utrecht-link" :href="address" data-testid="qr-link">{{
			linkText
		}}</a>
		<button
			v-if="compact"
			type="button"
			class="utrecht-button utrecht-button--subtle pq-qr-value__toggle"
			:aria-expanded="open ? 'true' : 'false'"
			data-testid="qr-toggle"
			@click="$emit('toggle')">
			{{ open ? words.hide : words.show }}
		</button>
		<span v-if="!compact || open" class="pq-qr-value__code">
			<QrCode
				:value="address"
				:label="codeLabel"
				:nameTemplate="words.name"
				:size="compact ? 128 : 160" />
			<span class="pq-qr-value__address" data-testid="qr-address">{{
				address
			}}</span>
			<span class="pq-qr-value__caption">{{ words.caption }}</span>
		</span>
	</span>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import { qrHref, qrLabel } from './cells.js'

// Loaded on demand with the code itself: the site entry has a size budget.
const STRINGS = {
	nl: {
		show: 'Toon QR-code',
		hide: 'Verberg QR-code',
		name: 'QR-code voor: {label}',
		caption: 'Scan deze code met je telefoon',
	},
	en: {
		show: 'Show QR code',
		hide: 'Hide QR code',
		name: 'QR code for: {label}',
		caption: 'Scan this code with your phone',
	},
}

/**
 * @spec openspec/changes/link-field-qr-code/tasks.md#t5
 */
export default {
	name: 'QrValue',

	components: {
		QrCode: defineAsyncComponent(() => import('./QrCode.vue')),
	},

	props: {
		/** The raw value of the field. */
		value: { type: String, default: '' },
		/** The column or field: `linkLabel`, `label`, `field`. */
		column: { type: Object, default: () => ({}) },
		/** In a table cell: the code opens on request. */
		compact: { type: Boolean, default: false },
		/** In a table cell: whether this cell's code is open. */
		open: { type: Boolean, default: false },
		/** The site's origin, to make a path absolute (test seam). */
		origin: { type: String, default: '' },
		/** The language, `nl` or `en`; the page's when empty. */
		locale: { type: String, default: '' },
	},

	emits: ['toggle'],

	computed: {
		/**
		 * @return {string} The address the code says, or '' when the value is none.
		 * @spec openspec/changes/link-field-qr-code/tasks.md#t3
		 */
		address() {
			const origin = this.origin || globalThis.window?.location?.origin || ''
			return qrHref(this.value, origin)
		},

		/**
		 * @return {string} The link's words: `linkLabel`, else the address.
		 * @spec openspec/changes/link-field-qr-code/tasks.md#t5
		 */
		linkText() {
			return typeof this.column?.linkLabel === 'string'
				&& this.column.linkLabel.trim() !== ''
				? this.column.linkLabel.trim()
				: this.value.trim()
		},

		/**
		 * @return {string} What the code opens, for its accessible name: the column's words, else the address.
		 * @spec openspec/changes/link-field-qr-code/tasks.md#t4
		 */
		codeLabel() {
			return qrLabel(this.column) || this.value.trim()
		},

		/**
		 * @return {Record<string, string>} The words in the page language.
		 * @spec openspec/changes/link-field-qr-code/tasks.md#t7
		 */
		words() {
			const lang = (
				this.locale
				|| globalThis.document?.documentElement?.lang
				|| 'nl'
			).toLowerCase()
			return lang.startsWith('en') ? STRINGS.en : STRINGS.nl
		},
	},
}
</script>

<style scoped>
.pq-qr-value {
	display: inline-flex;
	flex-direction: column;
	align-items: flex-start;
	gap: var(--utrecht-space-block-xs, 0.25rem);
}

.pq-qr-value__code {
	display: flex;
	flex-direction: column;
	gap: var(--utrecht-space-block-xs, 0.25rem);
}

.pq-qr-value__address {
	overflow-wrap: anywhere;
	user-select: all;
}
</style>
