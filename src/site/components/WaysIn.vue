<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div class="pq-ways-in">
		<section
			v-if="ways.register"
			class="pq-way-in"
			aria-labelledby="pq-way-in-register"
			data-testid="way-in-register">
			<h2 id="pq-way-in-register" class="utrecht-heading-3">
				{{ t('Create an account') }}
			</h2>
			<form @submit.prevent="register">
				<div class="utrecht-form-field">
					<label class="utrecht-form-label" for="pq-register-email">{{
						t('E-mail address')
					}}</label>
					<input
						id="pq-register-email"
						v-model="registration.email"
						class="utrecht-textbox"
						type="email"
						name="email"
						autocomplete="email"
						required />
				</div>
				<div class="utrecht-form-field">
					<label class="utrecht-form-label" for="pq-register-name">{{
						t('Your name')
					}}</label>
					<input
						id="pq-register-name"
						v-model="registration.name"
						class="utrecht-textbox"
						type="text"
						name="name"
						autocomplete="name" />
				</div>
				<!-- A field no person sees or fills; a bot that fills every field fills it. -->
				<div class="pq-way-in__trap">
					<label for="pq-register-website">{{
						t('Leave this field empty')
					}}</label>
					<input
						id="pq-register-website"
						v-model="registration.trap"
						type="text"
						name="website"
						tabindex="-1"
						autocomplete="off" />
				</div>
				<button
					type="submit"
					class="utrecht-button utrecht-button--primary-action"
					:disabled="registration.busy">
					{{
						registration.busy ? t('One moment…') : t('Create an account')
					}}
				</button>
			</form>
			<p
				v-if="registration.outcome"
				class="utrecht-paragraph"
				:role="registration.outcome.role"
				data-testid="way-in-register-result">
				{{ registration.outcome.text }}
			</p>
		</section>

		<section
			v-if="ways.reference"
			class="pq-way-in"
			aria-labelledby="pq-way-in-reference"
			data-testid="way-in-reference">
			<h2 id="pq-way-in-reference" class="utrecht-heading-3">
				{{ t('Follow a case with its case number') }}
			</h2>
			<form @submit.prevent="requestLink">
				<div
					v-if="ways.referenceCaseTypes.length > 1"
					class="utrecht-form-field">
					<label class="utrecht-form-label" for="pq-reference-type">{{
						t('Kind of case')
					}}</label>
					<select
						id="pq-reference-type"
						v-model.number="reference.choice"
						class="utrecht-select">
						<option
							v-for="(type, index) in ways.referenceCaseTypes"
							:key="`${type.register}/${type.schema}/${type.caseType}`"
							:value="index">
							{{ type.label || type.caseType }}
						</option>
					</select>
				</div>
				<div class="utrecht-form-field">
					<label class="utrecht-form-label" for="pq-reference-number">{{
						t('Case number')
					}}</label>
					<input
						id="pq-reference-number"
						v-model="reference.caseReference"
						class="utrecht-textbox"
						type="text"
						name="caseReference"
						autocomplete="off"
						required />
				</div>
				<div class="utrecht-form-field">
					<label class="utrecht-form-label" for="pq-reference-email">{{
						t('E-mail address')
					}}</label>
					<input
						id="pq-reference-email"
						v-model="reference.email"
						class="utrecht-textbox"
						type="email"
						name="email"
						autocomplete="email"
						required />
				</div>
				<button
					type="submit"
					class="utrecht-button utrecht-button--secondary-action"
					:disabled="reference.busy">
					{{ t('Send me a link') }}
				</button>
			</form>
			<p
				v-if="reference.outcome"
				class="utrecht-paragraph"
				:role="reference.outcome.role"
				data-testid="way-in-reference-result">
				{{ reference.outcome.text }}
			</p>
		</section>
	</div>
</template>

<script>
import {
	registrationOutcomeText,
	solveChallenge,
	wayInRefusalText,
	waysInApi,
} from '../lib/waysIn.js'

/**
 * The doors on the site's sign-in screen besides the sign-in buttons
 * (identity-ways-in-screens T02, T04): "Create an account" and "Follow a case
 * with its case number", each only when the site config's `waysIn` opens it.
 *
 * @spec openspec/changes/identity-ways-in-screens/specs/portal-ways-in/spec.md#requirement-the-sign-in-screen-shows-only-the-doors-that-lead-somewhere-req-iwi-005
 */
export default {
	name: 'WaysIn',

	props: {
		/** The doors, from `waysInFrom()`. */
		ways: { type: Object, required: true },
		/** The portal API base (`.../portal/api`). */
		authBase: { type: String, required: true },
		/** The serving portal's slug, or ''. */
		portal: { type: String, default: '' },
		/** The translator. */
		t: { type: Function, required: true },
	},

	data() {
		return {
			registration: {
				email: '',
				name: '',
				trap: '',
				busy: false,
				outcome: null,
			},

			reference: {
				choice: 0,
				caseReference: '',
				email: '',
				busy: false,
				outcome: null,
			},
		}
	},

	computed: {
		/**
		 * The identity routes for this portal.
		 *
		 * @return {object}
		 */
		api() {
			return waysInApi(this.authBase, this.portal)
		},
	},

	methods: {
		/**
		 * Solve the challenge and register; show the policy's outcome.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/identity-ways-in-screens/tasks.md#T02
		 */
		async register() {
			this.registration.busy = true
			this.registration.outcome = null
			const challenge = (await this.api.challenge('registration')) || {}
			const solution = challenge.difficulty
				? await solveChallenge(challenge.nonce, challenge.difficulty)
				: ''
			const answer = await this.api.registerAccount({
				email: this.registration.email,
				displayName: this.registration.name,
				challenge,
				solution,
				honeypot: challenge.honeypotField
					? {
							field: challenge.honeypotField,
							value: this.registration.trap,
						}
					: null,
			})
			this.registration.busy = false
			this.registration.outcome = answer.ok
				? {
						role: 'status',
						text: this.t(registrationOutcomeText(answer.data.awaiting)),
					}
				: { role: 'alert', text: this.t(wayInRefusalText(answer.error)) }
		},

		/**
		 * Ask for a reference link. The server answers the same whether or not
		 * the number and the address belong together, so the sentence does too.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/identity-ways-in-screens/tasks.md#T04
		 */
		async requestLink() {
			const type = this.ways.referenceCaseTypes[this.reference.choice]
			if (!type) {
				return
			}
			this.reference.busy = true
			const answer = await this.api.requestReferenceLink({
				register: type.register,
				schema: type.schema,
				caseType: type.caseType,
				caseReference: this.reference.caseReference,
				email: this.reference.email,
			})
			this.reference.busy = false
			this.reference.outcome = answer.ok
				? {
						role: 'status',
						text: this.t(
							'If the case number and the e-mail address belong together, we sent a link to that address. It works once.',
						),
					}
				: { role: 'alert', text: this.t(wayInRefusalText(answer.error)) }
		},
	},
}
</script>

<style scoped>
.pq-way-in {
	margin-block-start: var(--utrecht-space-block-lg, 2rem);
}

/* Out of sight and out of the tab order; still in the form for a bot. */
.pq-way-in__trap {
	position: absolute;
	inline-size: 1px;
	block-size: 1px;
	overflow: hidden;
	clip-path: inset(50%);
}
</style>
