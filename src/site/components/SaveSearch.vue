<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div v-if="offered" class="pq-save" data-testid="save-search">
		<button
			v-if="!open"
			type="button"
			class="utrecht-button utrecht-button--secondary-action"
			data-testid="save-search-open"
			@click="open = true">
			Bewaar deze zoekopdracht
		</button>

		<form
			v-else
			class="pq-save__form"
			data-testid="save-search-form"
			@submit.prevent="submit">
			<label class="utrecht-form-label" for="pq-save-search-title">
				Naam van de zoekopdracht
			</label>
			<input
				id="pq-save-search-title"
				v-model="title"
				type="text"
				class="utrecht-textbox"
				required
				data-testid="save-search-title" />

			<fieldset class="pq-save__choices">
				<legend class="utrecht-form-label">Hoe vaak wilt u bericht?</legend>
				<label
					v-for="option in frequencies"
					:key="option.value"
					class="utrecht-form-label utrecht-form-label--radio">
					<input
						v-model="frequency"
						type="radio"
						class="utrecht-radio-button"
						name="pq-save-search-frequency"
						:value="option.value"
						:data-testid="`save-search-frequency-${option.value}`" />
					{{ option.label }}
				</label>
			</fieldset>

			<button
				type="submit"
				class="utrecht-button utrecht-button--primary-action"
				:disabled="busy"
				data-testid="save-search-submit">
				Bewaren
			</button>
			<button
				type="button"
				class="utrecht-button utrecht-button--subtle"
				@click="open = false">
				Annuleren
			</button>
		</form>

		<p
			class="utrecht-paragraph pq-save__status"
			aria-live="polite"
			data-testid="save-search-status">
			{{ status }}
		</p>
	</div>
</template>

<script>
import {
	offeredActions,
	postAction,
	RESIDENT_ACTION_DEFAULTS,
	saveSearchBody,
	saveVisible,
} from '../lib/residentActions.js'
import {
	residentAuthBase,
	residentManifest,
	residentToken,
} from '../lib/residentSession.js'

/**
 * "Bewaar deze zoekopdracht" (woo-journey-entry-points, REQ-WJE-003).
 *
 * Mounted by the search block only for a signed-in resident, and it renders
 * nothing until the resident's manifest shows the action is offered.
 *
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-a-resident-must-be-able-to-save-the-current-search-req-wje-003
 */
export default {
	name: 'SaveSearch',

	props: {
		/** The search as the C2 query object. */
		query: {
			type: Object,
			required: true,
		},

		/** The app that offers the action. */
		app: {
			type: String,
			default: RESIDENT_ACTION_DEFAULTS.app,
		},

		/** The action id. */
		actionId: {
			type: String,
			default: RESIDENT_ACTION_DEFAULTS.saveSearchAction,
		},
	},

	data() {
		return {
			offered: false,
			open: false,
			busy: false,
			title: '',
			frequency: 'daily',
			status: '',
			frequencies: [
				{ value: 'immediate', label: 'Direct' },
				{ value: 'daily', label: 'Dagelijks' },
				{ value: 'weekly', label: 'Wekelijks' },
			],
		}
	},

	watch: {
		/**
		 * Offer the search text as the name.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-a-resident-must-be-able-to-save-the-current-search-req-wje-003
		 */
		open() {
			if (this.open === true && this.title === '') {
				this.title = this.query.text || ''
			}
		},
	},

	/**
	 * Show the button only when the action is offered.
	 *
	 * @return {Promise<void>}
	 *
	 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-the-save-actions-must-show-only-to-a-signed-in-resident-who-is-offered-them-req-wje-001
	 */
	async mounted() {
		const manifest = await residentManifest()
		this.offered = saveVisible(
			true,
			offeredActions(manifest, this.app),
			this.actionId,
		)
	},

	methods: {
		/**
		 * Save the search.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-a-resident-must-be-able-to-save-the-current-search-req-wje-003
		 */
		async submit() {
			this.busy = true
			const result = await postAction(
				residentAuthBase(),
				this.app,
				this.actionId,
				saveSearchBody({
					title: this.title,
					frequency: this.frequency,
					query: this.query,
				}),
				residentToken(),
			)
			this.busy = false

			if (result.ok === true) {
				this.open = false
				this.status =
					'Zoekopdracht bewaard. U krijgt bericht als er iets nieuws is. Beheer uw zoekopdrachten in Mijn zoekopdrachten.'
				return
			}

			this.status = 'Bewaren is niet gelukt. Probeer het later opnieuw.'
		},
	},
}
</script>

<style scoped>
.pq-save__form {
	display: grid;
	gap: 8px;
	margin-block: 8px;
	max-inline-size: 32rem;
}

.pq-save__choices {
	border: 0;
	margin: 0;
	padding: 0;
}

.pq-save__status:empty {
	display: none;
}
</style>
