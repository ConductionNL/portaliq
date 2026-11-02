<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The Dutch address block (data-lookups-and-checks-in-forms REQ-DIF-001),
	drawn on the Zuiddrecht board FormulierVelden: postcode, house number and
	an addition, then street and town. When the form's binding switches the
	lookup on, leaving the house number asks the register for the street and
	town and fills them. Both stay editable, and the sentence under them says
	so. A miss says nothing: the resident types.
-->
<template>
	<div class="pq-address" :data-testid="testid">
		<div class="pq-address__row">
			<div class="pq-address__cell">
				<label class="utrecht-form-label" :for="`${id}-postcode`">{{
					words.postcode
				}}</label>
				<input
					:id="`${id}-postcode`"
					:value="block.postcode"
					class="utrecht-textbox utrecht-textbox--html-input"
					type="text"
					autocomplete="postal-code"
					:aria-invalid="invalid ? 'true' : 'false'"
					:data-testid="`${testid}-postcode`"
					@input="change('postcode', $event.target.value)"
					@blur="find" />
			</div>
			<div class="pq-address__cell">
				<label class="utrecht-form-label" :for="`${id}-number`">{{
					words.number
				}}</label>
				<input
					:id="`${id}-number`"
					:value="block.number"
					class="utrecht-textbox utrecht-textbox--html-input"
					type="text"
					inputmode="numeric"
					:aria-invalid="invalid ? 'true' : 'false'"
					:data-testid="`${testid}-number`"
					@input="change('number', $event.target.value)"
					@blur="find" />
			</div>
			<div v-if="houseLetter" class="pq-address__cell">
				<label class="utrecht-form-label" :for="`${id}-letter`">{{
					words.letter
				}}</label>
				<input
					:id="`${id}-letter`"
					:value="block.letter"
					class="utrecht-textbox utrecht-textbox--html-input"
					type="text"
					maxlength="1"
					@input="change('letter', $event.target.value)"
					@blur="find" />
			</div>
			<div class="pq-address__cell">
				<label class="utrecht-form-label" :for="`${id}-addition`">{{
					words.addition
				}}</label>
				<input
					:id="`${id}-addition`"
					:value="block.addition"
					class="utrecht-textbox utrecht-textbox--html-input"
					type="text"
					maxlength="4"
					@input="change('addition', $event.target.value)"
					@blur="find" />
			</div>
		</div>

		<p
			v-if="foundNote"
			class="utrecht-paragraph"
			role="status"
			data-testid="address-found">
			{{ words.found }}
		</p>

		<div class="pq-address__row">
			<div class="pq-address__cell">
				<label class="utrecht-form-label" :for="`${id}-street`">{{
					words.street
				}}</label>
				<input
					:id="`${id}-street`"
					:value="block.street"
					class="utrecht-textbox utrecht-textbox--html-input"
					type="text"
					autocomplete="address-line1"
					:data-testid="`${testid}-street`"
					@input="edit('street', $event.target.value)" />
			</div>
			<div class="pq-address__cell">
				<label class="utrecht-form-label" :for="`${id}-town`">{{
					words.town
				}}</label>
				<input
					:id="`${id}-town`"
					:value="block.town"
					class="utrecht-textbox utrecht-textbox--html-input"
					type="text"
					autocomplete="address-level2"
					:data-testid="`${testid}-town`"
					@input="edit('town', $event.target.value)" />
			</div>
		</div>
	</div>
</template>

<script>
import { lookupAddress } from '../../lib/intakeApi.js'
import { pageLocale } from '../../pages/inbox/translate.js'
import { canLookUp, emptyAddress, withFound } from './address.js'

import '@utrecht/form-label-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'
import '@utrecht/textbox-css/dist/index.css'

const WORDS = {
	nl: {
		postcode: 'Postcode',
		number: 'Huisnummer',
		letter: 'Huisletter',
		addition: 'Toevoeging (niet verplicht)',
		street: 'Straat',
		town: 'Plaats',
		found: 'Wij vonden dit adres bij uw postcode en huisnummer. Klopt het niet? Pas de straat of plaats dan zelf aan.',
	},
	en: {
		postcode: 'Postcode',
		number: 'House number',
		letter: 'House letter',
		addition: 'Addition (optional)',
		street: 'Street',
		town: 'Town',
		found: 'We found this address for your postcode and house number. Is it wrong? Change the street or town yourself.',
	},
}

/**
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t01
 */
export default {
	name: 'AddressNL',

	props: {
		/** The block: `{postcode, number, letter, addition, street, town}`. */
		modelValue: { type: [Object, String], default: () => emptyAddress() },
		/** The element id the label group hangs on. */
		id: { type: String, default: 'address' },
		/** Ask for a house letter as well (the property's address). */
		houseLetter: { type: Boolean, default: false },
		/** Look the street and town up (the binding's `addressLookup`). */
		lookup: { type: Boolean, default: false },
		/** The portal api base the lookup route lives under. */
		base: { type: String, default: '' },
		/** Whether the block has an error. */
		invalid: { type: Boolean, default: false },
		/** The test id of the block. */
		testid: { type: String, default: 'address-block' },
		/** The reader's locale; the page language when empty. */
		locale: { type: String, default: '' },
	},

	emits: ['update:modelValue'],

	data() {
		return { touched: { street: false, town: false }, foundNote: false }
	},

	computed: {
		/**
		 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t01
		 */
		block() {
			return typeof this.modelValue === 'object' && this.modelValue
				? { ...emptyAddress(), ...this.modelValue }
				: emptyAddress()
		},

		/**
		 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t01
		 */
		words() {
			return WORDS[pageLocale(this.locale)] || WORDS.nl
		},
	},

	methods: {
		/**
		 * @param {string} key The part of the block.
		 * @param {string} value The typed value.
		 * @return {void}
		 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t01
		 */
		change(key, value) {
			this.$emit('update:modelValue', { ...this.block, [key]: value })
		},

		/**
		 * A street or town typed by hand is the resident's from then on.
		 *
		 * @param {string} key `street` or `town`.
		 * @param {string} value The typed value.
		 * @return {void}
		 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t01
		 */
		edit(key, value) {
			this.touched = { ...this.touched, [key]: true }
			this.change(key, value)
		},

		/**
		 * Ask the register for the street and town. A miss changes nothing.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t01
		 */
		async find() {
			if (!this.lookup || !canLookUp(this.block)) {
				return
			}
			const asked = { ...this.block }
			const found = await lookupAddress(this.base, asked)
			const unchanged =
				this.block.postcode === asked.postcode
				&& this.block.number === asked.number
				&& this.block.letter === asked.letter
				&& this.block.addition === asked.addition
			if (!found || !unchanged) {
				return
			}
			this.foundNote = true
			this.$emit(
				'update:modelValue',
				withFound(this.block, found, this.touched),
			)
		},
	},
}
</script>

<style scoped>
.pq-address__row {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-md, 1rem);
	margin-block-end: var(--utrecht-space-block-sm, 0.5rem);
}

.pq-address__cell {
	display: grid;
	gap: 0.25rem;
}
</style>
