<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"My details" on the site (identity-registered-details T05-T07), ported from
	src/portal/components/RegisteredDetailsPage.jsx. What the base registrations
	hold about the signed-in person: the BRP record for a resident, the KvK
	record for a business user. Read when the screen opens, never stored. Each
	empty state says why. The request links appear only when the portal bound a
	published form to them.
-->
<template>
	<p
		v-if="details === null"
		class="utrecht-paragraph"
		role="status"
		data-testid="details-loading">
		{{ t('Loading…') }}
	</p>
	<section
		v-else
		class="pq-details"
		aria-labelledby="pq-details-title"
		data-testid="registered-details">
		<!-- The page title: the shell leaves its own h1 out for this page
		     (OWNS_HEADING in pages/registry.js). -->
		<h1 id="pq-details-title" class="utrecht-heading-2">
			{{ t('My details') }}
		</h1>
		<p
			v-if="details.available !== true"
			class="utrecht-paragraph"
			role="status"
			data-testid="details-unavailable">
			{{ t(reason) }}
		</p>

		<template v-if="details.available === true && details.kind === 'person'">
			<p class="utrecht-paragraph">
				{{
					t(
						'These are the details the Personal Records Database (BRP) holds about you.',
					)
				}}
			</p>
			<dl class="pq-details__list">
				<div
					v-for="row in personRows"
					:key="row.label"
					class="pq-details__row">
					<dt>{{ row.label }}</dt>
					<dd>{{ row.value }}</dd>
				</div>
			</dl>
			<h3 class="utrecht-heading-3">
				{{ t('Address') }}
			</h3>
			<p class="utrecht-paragraph" data-testid="details-address">
				{{ address[0] }}<br />{{ address[1] }}
			</p>
			<p class="utrecht-paragraph">
				{{ residentsLine }}
			</p>
			<p v-if="links.addressInvestigation" class="utrecht-paragraph">
				<a class="utrecht-link" :href="links.addressInvestigation">{{
					t('Something wrong at this address?')
				}}</a>
			</p>
		</template>

		<template v-if="details.available === true && details.kind === 'company'">
			<p class="utrecht-paragraph">
				{{
					t(
						'These are the details the Chamber of Commerce (KvK) holds about your company.',
					)
				}}
			</p>
			<dl class="pq-details__list">
				<div
					v-for="row in companyRows"
					:key="row.label"
					class="pq-details__row">
					<dt>{{ row.label }}</dt>
					<dd>{{ row.value }}</dd>
				</div>
			</dl>
			<h3 class="utrecht-heading-3">
				{{ t('Branches') }}
			</h3>
			<p v-if="branches.length === 0" class="utrecht-paragraph">
				{{ t('The KvK lists no branches for this company.') }}
			</p>
			<ul v-else class="utrecht-unordered-list pq-details__branches">
				<li
					v-for="branch in branches"
					:key="branch.number"
					class="utrecht-unordered-list__item">
					<strong>{{ branch.name }}</strong>
					<template v-if="branch.main">
						({{ t('Main branch') }})
					</template>
					<br />{{
						t('Branch number {number}', { number: branch.number })
					}}
					<template v-if="branch.address">
						<br />{{ branch.address }}
					</template>
				</li>
			</ul>
		</template>

		<p
			v-if="details.available === true && links.correction"
			class="utrecht-paragraph">
			<a class="utrecht-link" :href="links.correction">{{
				t('Report an error in these details')
			}}</a>
		</p>
	</section>
</template>

<script>
import { calendarDate, readerLocale } from './format.js'
import { addressLines, reasonText } from './registeredDetails.js'

export default {
	name: 'RegisteredDetailsPage',

	props: {
		/** The session as `/portal/api/session` returns it. */
		session: { type: Object, default: null },
		/** The portal record. */
		portal: { type: Object, default: null },
		/** The portal API adapter (`createPortalApi` shape). */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
		/** The shell's `navigate(key, params)`. */
		navigate: { type: Function, default: () => {} },
		/** The reader's locale; the page's `<html lang>` when empty. */
		locale: { type: String, default: '' },
		/** An answer to show without fetching (test seam). */
		initialDetails: { type: Object, default: null },
	},

	data() {
		return { details: this.initialDetails, live: true }
	},

	computed: {
		links() {
			return this.details?.links || {}
		},

		reason() {
			return reasonText(this.details?.reason)
		},

		person() {
			return this.details?.person || {}
		},

		personRows() {
			return [
				{ label: this.t('Name'), value: this.person.name },
				{
					label: this.t('Date of birth'),
					value: calendarDate(
						this.person.birthDate,
						readerLocale(this.locale),
					),
				},
			].filter((row) => row.value)
		},

		address() {
			return addressLines(this.person.address)
		},

		residentsLine() {
			const count = this.person.residentsAtAddress
			return Number.isInteger(count)
				? this.t('{count} people are registered at this address.', { count })
				: this.t('The number of residents at this address is not available.')
		},

		company() {
			return this.details?.company || {}
		},

		companyRows() {
			return [
				{ label: this.t('Trade name'), value: this.company.tradeName },
				{ label: this.t('KvK number'), value: this.company.kvkNumber },
				{ label: this.t('Legal form'), value: this.company.legalForm },
			].filter((row) => row.value)
		},

		branches() {
			return Array.isArray(this.company.branches) ? this.company.branches : []
		},
	},

	/**
	 * Read the details when the screen opens.
	 *
	 * @return {Promise<void>} Resolves when read.
	 *
	 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-see-their-registered-details-req-srp-038
	 */
	async mounted() {
		if (this.initialDetails !== null) {
			return
		}
		const answer = await this.api.fetchRegisteredDetails()
		if (this.live) {
			this.details = answer || { available: false, reason: 'unavailable' }
		}
	},

	beforeUnmount() {
		this.live = false
	},
}
</script>

<style scoped>
.pq-details > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-details__row {
	display: grid;
	grid-template-columns: minmax(8rem, 1fr) 2fr;
	gap: var(--utrecht-space-inline-md, 1rem);
}

.pq-details__row dt {
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}

.pq-details__row dd {
	margin: 0;
}
</style>
