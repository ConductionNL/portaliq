<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"Namens wie u werkt" (site-mandates-the-represented-manage T4, board
	Machtigingen). The acting-for choice, and per mandate the session holds
	"Uw machtiging voor ...": what it covers, when it was given, its end, and
	"Machtiging stoppen", which asks once more before it stops. A stopped
	mandate the session was acting under falls back to acting for oneself.
	A person who holds no mandate sees nothing here.
-->
<template>
	<section
		v-if="held.length > 0"
		class="pq-mandates-held"
		aria-labelledby="pq-mandates-held-title"
		data-testid="mandates-held">
		<h2 id="pq-mandates-held-title" class="utrecht-heading-3">
			{{ t('On whose behalf you work') }}
		</h2>
		<ActingForSwitcher :t="t" />
		<p class="utrecht-paragraph">
			{{
				t(
					'Do you choose a company or person? Then My cases shows their cases.',
				)
			}}
		</p>
		<p
			v-if="problem !== ''"
			class="utrecht-paragraph pq-mandates-held__error"
			role="alert">
			{{ t(problem) }}
		</p>
		<article
			v-for="mandate in held"
			:key="mandate.id"
			class="pq-mandates-held__card"
			data-testid="mandates-held-card">
			<h3 class="utrecht-heading-4">
				{{ t('Your mandate for {party}', { party: partyOf(mandate) }) }}
			</h3>
			<p class="utrecht-paragraph">
				{{ line(mandate) }}
			</p>
			<p v-if="confirming === mandate.id" class="pq-mandates-held__buttons">
				<span>{{
					t('Stop this mandate? You can no longer act for them.')
				}}</span>
				<button
					type="button"
					class="utrecht-button utrecht-button--primary-action"
					data-testid="mandates-held-stop-confirm"
					@click="stop(mandate)">
					{{ t('Stop mandate') }}
				</button>
				<button
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					@click="confirming = ''">
					{{ t('Cancel') }}
				</button>
			</p>
			<button
				v-else
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				data-testid="mandates-held-stop"
				@click="confirming = mandate.id">
				{{ t('Stop mandate') }}
			</button>
		</article>
	</section>
</template>

<script>
import ActingForSwitcher from './ActingForSwitcher.vue'
import { mandatesApi } from '../../../shared/areaApi.js'
import { ACTING_FOR_SELF } from '../../../shared/myCases.js'
import { longDate, readerLocale } from '../../pages/e/format.js'
import { actingFor, chooseActingFor } from './actingFor.js'
import { endText, scopeText } from './mandates.js'

export default {
	name: 'MandatesHeld',

	components: { ActingForSwitcher },

	props: {
		/** The portal API adapter (`createPortalApi` shape). */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
		/** The portal record; its slug names the case types' titles. */
		portal: { type: Object, default: null },
		/** The reader's locale; the page's `<html lang>` when empty. */
		locale: { type: String, default: '' },
	},

	emits: ['stopped'],

	data() {
		return { held: [], caseTypes: [], confirming: '', problem: '' }
	},

	/**
	 * Read the mandates the session holds when the section opens.
	 *
	 * @return {Promise<void>} Resolves when read.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4
	 */
	async mounted() {
		const client = mandatesApi(this.api)
		this.held = await client.held()
		if (this.held.some((mandate) => (mandate.caseTypes || []).length > 0)) {
			this.caseTypes = await client.caseTypes(this.portal?.slug || '')
		}
	},

	methods: {
		/**
		 * The party a mandate is for, without its type.
		 *
		 * @param {object} mandate The mandate.
		 * @return {string}
		 * @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4
		 */
		partyOf(mandate) {
			const known = (actingFor.mandates || []).find(
				(entry) => String(entry.id) === String(mandate.id),
			)
			return (
				known?.label
				|| String(mandate.onBehalfOf || '').replace(/^(kvk|subject):/, '')
			)
		},

		/**
		 * "All cases · given by the municipality on 12 September 2026 · no end date".
		 *
		 * @param {object} mandate The mandate.
		 * @return {string}
		 * @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4
		 */
		line(mandate) {
			const format = (value) => longDate(value, readerLocale(this.locale))
			const vars = {
				party: this.partyOf(mandate),
				date: mandate.grantedAt ? format(mandate.grantedAt) : '',
			}
			let given = this.t('given by the municipality', vars)
			if (mandate.givenBy === 'party') {
				given = vars.date
					? this.t('given by {party} on {date}', vars)
					: this.t('given by {party}', vars)
			} else if (vars.date) {
				given = this.t('given by the municipality on {date}', vars)
			}
			const parts = [
				mandate.label
					|| scopeText(mandate.caseTypes, this.caseTypes, this.t),
				given,
			]
			parts.push(endText(mandate.expiresAt, format, this.t))
			return parts.join(' · ')
		},

		/**
		 * Stop a mandate; acting under it falls back to acting for oneself.
		 *
		 * @param {object} mandate The mandate.
		 * @return {Promise<void>} Resolves when done.
		 * @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4
		 */
		async stop(mandate) {
			this.problem = ''
			const client = mandatesApi(this.api)
			const answer = await client.stop(mandate.id)
			this.confirming = ''
			if (!answer.ok) {
				this.problem = 'That did not work. Try again later.'
				return
			}
			if (String(actingFor.id) === String(mandate.id)) {
				chooseActingFor(ACTING_FOR_SELF)
			}
			this.held = await client.held()
			this.$emit('stopped', mandate.id)
		},
	},
}
</script>

<style scoped>
.pq-mandates-held > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-mandates-held__card > * + * {
	margin-block-start: var(--utrecht-space-block-sm, 0.5rem);
}

.pq-mandates-held__buttons {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-mandates-held__error {
	color: var(--utrecht-feedback-danger-color, currentcolor);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}
</style>
