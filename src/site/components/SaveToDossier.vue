<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div v-if="offered" class="pq-save" data-testid="save-to-dossier">
		<button
			v-if="!open"
			type="button"
			class="utrecht-button utrecht-button--secondary-action"
			:aria-label="subject ? `Bewaar ${subject} in mijn dossier` : undefined"
			data-testid="save-to-dossier-open"
			@click="openForm">
			Bewaar in mijn dossier
		</button>

		<form
			v-else
			class="pq-save__form"
			data-testid="save-to-dossier-form"
			@submit.prevent="submit">
			<label class="utrecht-form-label" :for="`${uid}-dossier`">
				Dossier
			</label>
			<select
				:id="`${uid}-dossier`"
				v-model="target"
				class="utrecht-select"
				data-testid="save-to-dossier-select">
				<option
					v-for="dossier in dossiers"
					:key="dossier.id"
					:value="dossier.id">
					{{ dossier.title }}
				</option>
				<option value="">Nieuw dossier</option>
			</select>

			<template v-if="target === ''">
				<label class="utrecht-form-label" :for="`${uid}-title`">
					Naam van het nieuwe dossier
				</label>
				<input
					:id="`${uid}-title`"
					v-model="title"
					type="text"
					class="utrecht-textbox"
					required
					data-testid="save-to-dossier-title" />
			</template>

			<button
				type="submit"
				class="utrecht-button utrecht-button--primary-action"
				:disabled="busy"
				data-testid="save-to-dossier-submit">
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
			data-testid="save-to-dossier-status">
			{{ status }}
		</p>
	</div>
</template>

<script>
import {
	addToCollectionBody,
	dossierCollectionOf,
	getJson,
	offeredActions,
	postAction,
	RESIDENT_ACTION_DEFAULTS,
	saveVisible,
} from '../lib/residentActions.js'
import {
	residentAuthBase,
	residentManifest,
	residentToken,
} from '../lib/residentSession.js'

let counter = 0

/**
 * "Bewaar in mijn dossier" (woo-journey-entry-points, REQ-WJE-002).
 *
 * One per publication, and one per document of it. Mounted only for a
 * signed-in resident; renders nothing until the manifest offers the action.
 * The dossier list is read when the resident opens the form, not before:
 * most visitors read and leave.
 *
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-a-resident-must-be-able-to-keep-a-publication-or-one-document-in-a-dossier-req-wje-002
 */
export default {
	name: 'SaveToDossier',

	props: {
		/** The publication id. */
		publication: {
			type: String,
			required: true,
		},

		/** The document's file id, or '' for the whole publication. */
		attachment: {
			type: String,
			default: '',
		},

		/** What is being kept, for the button's accessible name. */
		subject: {
			type: String,
			default: '',
		},

		/** The app that offers the action. */
		app: {
			type: String,
			default: RESIDENT_ACTION_DEFAULTS.app,
		},

		/** The action id. */
		actionId: {
			type: String,
			default: RESIDENT_ACTION_DEFAULTS.addAction,
		},

		/** The schema of the resident's dossiers in that app. */
		dossierSchema: {
			type: String,
			default: RESIDENT_ACTION_DEFAULTS.dossierSchema,
		},
	},

	data() {
		counter++
		return {
			uid: `pq-save-dossier-${counter}`,
			offered: false,
			collection: null,
			open: false,
			busy: false,
			dossiers: [],
			target: '',
			title: '',
			status: '',
		}
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
		this.collection = dossierCollectionOf(manifest, this.app, this.dossierSchema)
		this.offered = saveVisible(
			true,
			offeredActions(manifest, this.app),
			this.actionId,
		)
	},

	methods: {
		/**
		 * Open the form and read the resident's dossiers.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-a-resident-must-be-able-to-keep-a-publication-or-one-document-in-a-dossier-req-wje-002
		 */
		async openForm() {
			this.open = true
			this.status = ''
			if (this.collection === null) {
				return
			}

			const { id, register, schema } = this.collection
			const answer = await getJson(
				`${residentAuthBase()}/collections/${encodeURIComponent(register)}/${encodeURIComponent(schema)}?collection=${encodeURIComponent(id)}`,
				residentToken(),
			)
			this.dossiers = ((answer && answer.objects) || [])
				.map((row) => ({
					id: String(row.id || (row['@self'] || {}).id || ''),
					title: String(row.title || 'Zonder titel'),
				}))
				.filter((dossier) => dossier.id !== '')
			this.target = this.dossiers.length > 0 ? this.dossiers[0].id : ''
		},

		/**
		 * Keep the publication, or the document, in the chosen dossier.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/woo-journey-entry-points/specs/portal-federated-search/spec.md#requirement-a-resident-must-be-able-to-keep-a-publication-or-one-document-in-a-dossier-req-wje-002
		 */
		async submit() {
			this.busy = true
			const chosen = this.dossiers.find(
				(dossier) => dossier.id === this.target,
			)
			const result = await postAction(
				residentAuthBase(),
				this.app,
				this.actionId,
				addToCollectionBody({
					collection: this.target,
					title: this.title,
					publication: this.publication,
					attachment: this.attachment,
				}),
				residentToken(),
			)
			this.busy = false

			if (result.ok === true) {
				this.open = false
				this.status = `Bewaard in ${chosen ? chosen.title : this.title.trim()}.`
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

.pq-save__status:empty {
	display: none;
}
</style>
