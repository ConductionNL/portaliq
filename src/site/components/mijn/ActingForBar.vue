<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	While a resident acts for someone else, a bar on every signed-in page
	names that party and offers the way back to acting for themselves
	("U regelt nu zaken voor H. Bakker", "Wissel naar uzelf"). It is a named
	region, so a screen reader user can find it, and it shows nothing while
	the resident acts for themselves.
-->
<template>
	<section
		v-if="party"
		class="pq-acting-for-bar"
		:aria-label="tr('On whose behalf you act')"
		data-testid="mijn-acting-for-bar">
		<p class="utrecht-paragraph pq-acting-for-bar__text">
			<template v-if="marked"
				>{{ marked.before }}<NoTranslate :value="marked.value" />{{
					marked.after
				}}</template
			>
			<template v-else>{{
				tr('You are now acting for {party}', { party })
			}}</template>
		</p>
		<button
			type="button"
			class="utrecht-button utrecht-button--secondary-action pq-acting-for-bar__back"
			data-testid="mijn-acting-for-bar-back"
			@click="backToSelf">
			{{ tr('Switch to yourself') }}
		</button>
	</section>
</template>

<script>
import NoTranslate from '../NoTranslate.vue'
import { ACTING_FOR_SELF } from '../../../shared/myCases.js'
import { markAround } from '../../lib/markAround.js'
import { actingFor, chooseActingFor } from '../e/actingFor.js'
import { mijnTranslator } from './rows.js'

/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
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
		/** The current choice; the shared store's when empty. */
		value: { type: String, default: '' },
	},

	emits: ['change'],

	computed: {
		/**
		 * @return {(key: string, vars?: object) => string} The translator.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
		 */
		tr() {
			return mijnTranslator(this.t, this.locale)
		},

		/**
		 * The party acted for, by its mandate's label; '' while acting for
		 * oneself or for a mandate no longer held.
		 *
		 * @return {string}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
		 */
		party() {
			const id = this.value || actingFor.id
			if (!id || id === ACTING_FOR_SELF) {
				return ''
			}
			const list = this.mandates ?? actingFor.mandates
			const mandate = (Array.isArray(list) ? list : []).find(
				(m) => m?.id === id,
			)
			return mandate ? String(mandate.label || mandate.id) : ''
		},

		/**
		 * The sentence split around the party's name, so the name stays untranslated.
		 *
		 * @return {{before: string, value: string, after: string}|null}
		 * @spec openspec/changes/personal-data-left-untranslated/specs/portaliq-cms/spec.md#requirement-browser-translation-leaves-names-and-personal-data-alone-req-pdu-001
		 */
		marked() {
			return markAround(
				this.tr('You are now acting for {party}', { party: this.party }),
				this.party,
			)
		},
	},

	methods: {
		/**
		 * Act for oneself again, for the rest of the session.
		 *
		 * @return {void}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
		 */
		backToSelf() {
			chooseActingFor(ACTING_FOR_SELF)
			this.$emit('change', ACTING_FOR_SELF)
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
</style>
