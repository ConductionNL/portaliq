<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<article class="utrecht-article" data-testid="contribution-page">
		<div class="container">
			<h1 class="utrecht-heading-1">{{ page.label || page.id }}</h1>

			<template v-for="(block, i) in blocks" :key="i">
				<!-- Prose the contributing app authored, sanitised by the same
				     block the CMS uses: it is the same class of input. -->
				<MarkdownBlock
					v-if="block.type === 'richText'"
					:source="block.markdown || ''" />

				<!--
					AN ACTION IS A FORM, AND A FORM NEEDS SOMEWHERE TO POST.

					`ContributionController::action()` answers 401 without a
					session — for EVERY action, including those a manifest marks
					`anonymous`. So a signed-out visitor is told that plainly
					rather than given a form that collects their answers and
					loses them at submit. The mismatch between the manifest's
					flag and the endpoint's posture is real and recorded as
					task 6.2; this renderer reports the behaviour the visitor
					actually meets.
				-->
				<section
					v-else-if="block.type === 'action'"
					class="pq-action"
					:data-action="block.action">
					<template v-if="actionFor(block.action)">
						<h2 class="utrecht-heading-2">
							{{ actionFor(block.action).label || block.action }}
						</h2>

						<p
							v-if="!session"
							class="utrecht-paragraph"
							data-testid="action-signin-required">
							U moet ingelogd zijn om dit formulier te versturen.
						</p>

						<form
							v-else
							:data-testid="`action-form-${block.action}`"
							@submit.prevent="submit(block.action)">
							<div
								v-for="field in actionFor(block.action).fields || []"
								:key="field"
								class="pq-action__field">
								<label :for="fieldId(block.action, field)">{{
									field
								}}</label>
								<input
									:id="fieldId(block.action, field)"
									v-model="values[block.action + ':' + field]"
									type="text"
									:name="field" />
							</div>

							<button type="submit" :disabled="busy === block.action">
								{{ busy === block.action ? 'Bezig…' : 'Versturen' }}
							</button>
						</form>

						<!--
							The outcome is stated in words. A submitted form that
							merely empties itself is indistinguishable from one
							that silently failed.
						-->
						<p
							v-if="result[block.action]"
							class="utrecht-paragraph"
							:role="result[block.action].ok ? 'status' : 'alert'"
							:data-testid="`action-result-${block.action}`">
							{{ result[block.action].message }}
						</p>
					</template>

					<p v-else class="utrecht-paragraph" data-testid="action-unknown">
						Deze actie is niet beschikbaar.
					</p>
				</section>
			</template>
		</div>
	</article>
</template>

<script>
import MarkdownBlock from './MarkdownBlock.vue'
import { submitAction } from '../lib/contributionApi.js'

/**
 * One page a contributing app publishes on this portal (ADR-046).
 *
 * WHY THIS EXISTS. The contributions index has always NAMED these pages while
 * nothing could open one, so its entries were deliberately inert text — a link
 * that goes nowhere is worse than plain words. This is the other half of that
 * bridge: the pages are routable, so the entries become links.
 *
 * A contributed page is a list of blocks the app declares: `richText` for prose
 * and `action` for something a visitor can do. Both arrive on the public
 * contributions contract, which is anonymous and publicly cacheable — so this
 * renders an INDEX of what is possible, never a visitor's own rows.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-content-api-must-be-sufficient-without-the-built-in-renderer
 */
export default {
	name: 'ContributionPage',

	components: { MarkdownBlock },

	props: {
		/** The contributed page record. */
		page: {
			type: Object,
			required: true,
		},

		/** The contribution it belongs to, for its action list. */
		contribution: {
			type: Object,
			required: true,
		},

		/** The visitor's session, or null. */
		session: {
			type: Object,
			default: null,
		},

		/** The auth edge base, for posting an action. */
		apiBase: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			/** Field values, keyed `actionId:field` so two forms cannot collide. */
			values: {},
			/** The action currently in flight, or ''. */
			busy: '',
			/** Per-action outcome: `{ok, message}`. */
			result: {},
		}
	},

	computed: {
		/**
		 * @return {Array} The page's blocks, always an array.
		 */
		blocks() {
			return Array.isArray(this.page.blocks) ? this.page.blocks : []
		},
	},

	methods: {
		/**
		 * The declared action for an id, or null.
		 *
		 * A block may name an action the contribution does not declare — the
		 * two lists are authored separately — and that has to render as
		 * "unavailable" rather than as a form with no destination.
		 *
		 * @param {string} id The action id.
		 * @return {object|null} The action.
		 */
		actionFor(id) {
			const actions = Array.isArray(this.contribution.actions)
				? this.contribution.actions
				: []
			return actions.find((a) => a && a.id === id) || null
		},

		/**
		 * A DOM id for a field, unique per action.
		 *
		 * @param {string} action The action id.
		 * @param {string} field  The field name.
		 * @return {string} The id.
		 */
		fieldId(action, field) {
			return `pq-${action}-${field}`.replace(/[^a-zA-Z0-9-]/g, '-')
		},

		/**
		 * Submit one action's fields.
		 *
		 * Only the fields the action DECLARES are sent: the form cannot grow a
		 * key the manifest did not name. That is the same whitelist the server
		 * applies, and the reason it is applied here too rather than posting
		 * the whole model.
		 *
		 * @param {string} id The action id.
		 * @return {Promise<void>} Resolves once the attempt is reported.
		 */
		async submit(id) {
			const action = this.actionFor(id)
			if (!action || this.busy) {
				return
			}

			const payload = {}
			for (const field of action.fields || []) {
				payload[field] = this.values[`${id}:${field}`] || ''
			}

			this.busy = id
			try {
				await submitAction({
					apiBase: this.apiBase,
					appId: this.contribution.app,
					actionId: id,
					payload,
					token: this.session ? this.session.token : '',
				})
				this.result = {
					...this.result,
					[id]: {
						ok: true,
						message: 'Verstuurd. U ontvangt een bevestiging.',
					},
				}
				for (const field of action.fields || []) {
					this.values[`${id}:${field}`] = ''
				}
			} catch (error) {
				this.result = {
					...this.result,
					[id]: {
						ok: false,
						message: `Versturen is niet gelukt (${error.status || 'fout'}). Probeer het later opnieuw.`,
					},
				}
			} finally {
				this.busy = ''
			}
		},
	},
}
</script>
