<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The acting-for bar on Mijn overzicht and Mijn zaken (board
	huisstijl/NamensBalk): one sentence that names for whom the resident acts,
	"Wissel naar" to switch to themselves, a party or everyone, and the link to
	arrange access. It shows nothing for a resident who holds no mandate.
	Without `value` it reads and keeps the shared acting-for store; with
	`value` the host keeps the choice and only hears `change`.
-->
<template>
	<section
		v-if="options.length > 2"
		class="pq-acting-for-bar"
		:aria-label="tr('On whose behalf you act')"
		data-testid="mijn-acting-for-bar">
		<p class="utrecht-paragraph pq-acting-for-bar__text">
			<template v-if="marked"
				>{{ marked.before }}<NoTranslate :value="marked.value" />{{
					marked.after
				}}</template
			>
			<template v-else>{{ sentence }}</template>
		</p>
		<span class="pq-acting-for-bar__switch">
			<label :for="selectId" class="utrecht-form-label">{{
				tr('Switch to')
			}}</label>
			<select
				:id="selectId"
				class="utrecht-select pq-acting-for-bar__select"
				:value="choice"
				data-testid="mijn-acting-for-bar-select"
				@change="choose($event.target.value)">
				<option
					v-for="option in options"
					:key="option.id"
					:value="option.id"
					:selected="option.id === choice"
					:translate="option.own ? null : 'no'">
					{{ option.label }}
				</option>
			</select>
			<a
				v-if="href"
				class="utrecht-link pq-acting-for-bar__manage"
				:href="href"
				data-testid="mijn-acting-for-bar-manage">
				{{ tr('Arrange access to cases') }}
			</a>
		</span>
	</section>
</template>

<script>
import NoTranslate from '../NoTranslate.vue'
import { markAround } from '../../lib/markAround.js'
import { actingFor } from '../e/actingFor.js'
import { barOptions, barSentence, choiceOf, keepChoice } from './actingForChoice.js'
import { mijnTranslator } from './rows.js'

/**
 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
 */
export default {
	name: 'ActingForBar',

	components: { NoTranslate },

	props: {
		/** The site translator. */
		t: { type: Function, default: null },
		/** The page language. */
		locale: { type: String, default: 'nl' },
		/** The mandates held; the shared acting-for store's when null. */
		mandates: { type: Array, default: null },
		/** The choice the host keeps; the shared store's when empty. */
		value: { type: String, default: '' },
		/** The resident's name, for "Uzelf, {name}". */
		name: { type: String, default: '' },
		/** Where the resident arranges access to cases; no link when empty. */
		href: { type: String, default: '' },
		/** The select's id, unique on the page. */
		selectId: { type: String, default: 'pq-acting-for-bar' },
	},

	emits: ['change'],

	computed: {
		/**
		 * @return {(key: string, vars?: object) => string} The translator.
		 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
		 */
		tr() {
			return mijnTranslator(this.t, this.locale)
		},

		/**
		 * @return {string} `everyone`, `self` or the mandate acted under.
		 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
		 */
		choice() {
			return this.value || choiceOf(actingFor)
		},

		/**
		 * @return {Array<{id: string, label: string, own: boolean}>} The options of "Wissel naar".
		 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
		 */
		options() {
			const mandates = this.mandates ?? actingFor.mandates
			return barOptions({ mandates, name: this.name }, this.tr)
		},

		/**
		 * @return {string} The chosen party's label; '' for oneself, everyone or a mandate no longer held.
		 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
		 */
		party() {
			const option = this.options.find((o) => !o.own && o.id === this.choice)
			return option ? option.label : ''
		},

		/**
		 * @return {string} The bar's sentence.
		 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
		 */
		sentence() {
			return barSentence({ choice: this.choice, party: this.party }, this.tr)
		},

		/**
		 * The sentence split around the party's name, so the name stays untranslated.
		 *
		 * @return {{before: string, value: string, after: string}|null}
		 * @spec openspec/changes/personal-data-left-untranslated/specs/site-chrome/spec.md#requirement-browser-translation-leaves-names-and-personal-data-alone-req-pdu-001
		 */
		marked() {
			return markAround(this.sentence, this.party)
		},
	},

	methods: {
		/**
		 * Switch to a choice; the store keeps it unless the host does.
		 *
		 * @param {string} id `everyone`, `self` or a mandate id.
		 * @return {void}
		 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
		 */
		choose(id) {
			if (!this.value) {
				keepChoice(id)
			}
			this.$emit('change', id)
		},
	},
}
</script>

<style scoped>
.pq-acting-for-bar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	margin-block-end: var(--utrecht-space-block-md, 1rem);
	padding: var(--utrecht-space-block-sm, 0.5rem)
		var(--utrecht-space-inline-md, 1rem);
	border-inline-start: 4px solid
		var(--utrecht-button-primary-action-background-color, currentcolor);
	background-color: var(--utrecht-color-grey-90, #f0f0f0);
	color: var(--utrecht-document-color, inherit);
}

.pq-acting-for-bar__text {
	margin: 0;
	font-weight: bold;
}

.pq-acting-for-bar__switch {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-acting-for-bar__select {
	min-inline-size: 0;
	max-inline-size: 100%;
}
</style>
