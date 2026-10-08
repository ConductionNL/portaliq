<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"Wie verhuist er met u mee?" (data-lookups-and-checks-in-forms REQ-DIF-004),
	drawn on the Zuiddrecht board FormulierGezinsleden: the partner and
	children the BRP holds on the resident's address, one checkbox card each
	with a name, the relation and the birth year, nothing more. What is wrong
	in the BRP is not fixed here, and the block says so. The answer is the list
	of chosen references; the server checks them against the BRP again.
-->
<template>
	<div class="pq-family" :data-testid="testid">
		<p v-if="state === 'loading'" class="utrecht-paragraph" role="status">
			{{ words.loading }}
		</p>
		<p
			v-else-if="state === 'unavailable'"
			class="utrecht-paragraph"
			role="alert"
			data-testid="family-unavailable">
			{{ words.unavailable }}
		</p>
		<template v-else>
			<p class="utrecht-paragraph" data-testid="family-intro">
				{{ words.found }}
			</p>
			<ul class="pq-family__cards">
				<li v-for="person in people" :key="person.ref" class="pq-family__card">
					<label>
						<input
							type="checkbox"
							:checked="chosen.includes(person.ref)"
							:data-testid="`family-${person.ref}`"
							@change="toggle(person.ref)" />
						<span class="pq-family__name">{{ person.name }}</span>
						<span class="pq-family__meta">{{ metaOf(person) }}</span>
					</label>
				</li>
			</ul>
			<p class="utrecht-paragraph" data-testid="family-note">
				{{ words.note }}
			</p>
		</template>
	</div>
</template>

<script>
import { adoptSessionToken } from '../../lib/authApi.js'
import { fetchFamily } from '../../lib/intakeApi.js'
import { pageLocale } from '../../pages/inbox/translate.js'
import { toggleRef } from './family.js'

import '@utrecht/paragraph-css/dist/index.css'

const WORDS = {
	nl: {
		loading: 'Laden…',
		unavailable:
			'Wij kunnen uw gezinsleden nu niet ophalen. Probeer het later opnieuw.',
		found: 'Wij vonden deze personen op uw adres. Kies wie er met u meeverhuist.',
		note: 'Staat er iemand niet bij of klopt een gegeven niet? Dat regelt u niet in dit formulier. Neem contact met ons op, dan zoeken wij het uit.',
		partner: 'Partner',
		child: 'Kind',
		born: 'geboren in {year}',
	},
	en: {
		loading: 'Loading…',
		unavailable: 'We cannot fetch your family members right now. Try again later.',
		found: 'We found these people at your address. Choose who is moving with you.',
		note: 'Is someone missing, or is a detail wrong? You cannot fix that in this form. Contact us and we will look into it.',
		partner: 'Partner',
		child: 'Child',
		born: 'born in {year}',
	},
}

/**
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
 */
export default {
	name: 'FamilyMembers',

	props: {
		/** The chosen references. */
		modelValue: { type: Array, default: () => [] },
		/** The portal api base. */
		base: { type: String, default: '' },
		/** Keep only people at the resident's address. */
		sameAddressOnly: { type: Boolean, default: true },
		/** The test id of the block. */
		testid: { type: String, default: 'family-members' },
		/** The reader's locale; the page language when empty. */
		locale: { type: String, default: '' },
		/** The people already read (test seam). */
		initialPeople: { type: Array, default: null },
	},

	emits: ['update:modelValue'],

	data() {
		return {
			people: this.initialPeople || [],
			state: this.initialPeople ? 'ready' : 'loading',
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
		 */
		words() {
			return WORDS[pageLocale(this.locale)] || WORDS.nl
		},

		/**
		 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
		 */
		chosen() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},
	},

	/**
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
	 */
	mounted() {
		if (this.initialPeople === null) {
			this.load()
		}
	},

	methods: {
		/**
		 * Read the people the BRP backs. Failure shows the sentence and no cards.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
		 */
		async load() {
			const people = await fetchFamily(
				this.base,
				adoptSessionToken() || '',
				this.sameAddressOnly,
			)
			this.people = people || []
			this.state = people === null ? 'unavailable' : 'ready'
		},

		/**
		 * @param {object} person A listed person.
		 * @return {string} "Partner, geboren in 1983".
		 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
		 */
		metaOf(person) {
			const relation = this.words[person.relation] || ''
			const born = person.birthYear
				? this.words.born.split('{year}').join(person.birthYear)
				: ''
			return [relation, born].filter(Boolean).join(', ')
		},

		/**
		 * @param {string} ref The reference of the person ticked or unticked.
		 * @return {void}
		 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
		 */
		toggle(ref) {
			this.$emit('update:modelValue', toggleRef(this.chosen, ref))
		},
	},
}
</script>

<style scoped>
.pq-family__cards {
	display: grid;
	gap: var(--utrecht-space-block-sm, 0.5rem);
	padding: 0;
	list-style: none;
}

.pq-family__card {
	padding: var(--utrecht-space-block-sm, 0.75rem);
	border: 1px solid var(--utrecht-color-grey-400, #c4c7cc);
	border-radius: var(--utrecht-border-radius-md, 4px);
}

.pq-family__name {
	margin-inline: 0.5rem;
	font-weight: 600;
}
</style>
