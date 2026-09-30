/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Registration widget on a portal's page, without the widget
 * (identity-staff-account-screens T07): who may make an account on this
 * portal, from which addresses, and the registrations waiting for a
 * decision.
 *
 * WHERE THE POLICY LIVES. `portal.authentication.registration` on the portal
 * record, which `PortalRegistrationPolicyService` reads when a stranger
 * registers. The widget reads the portal fresh and writes it back whole
 * through OpenRegister, keeping every other sign-in setting.
 *
 * WHERE THE WAITING LIST COMES FROM. `portalAccount` rows with status
 * `pending` and `provisionedBy: self-registration` in the portal's
 * organisation. Approve and Refuse go through `PortalAccountAdminController`
 * behind `portal.provision`, which only accepts a self-registration.
 *
 * WHY THIS MODULE IMPORTS NOTHING. The GET, PUT, POST and URL generator are
 * handed in by `src/widgets/PortalRegistration.vue`, so
 * `tests/staff-account-screens.spec.mjs` runs it as a plain node script.
 *
 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-set-the-registration-policy-and-approve-registrations-req-isa-004
 */

/**
 * The three policies, as the portal schema's enum names them.
 */
export const POLICIES = ['off', 'approval', 'activation']

/**
 * The portal's registration, with `off` when it declares none.
 *
 * @param {object|null} portal The portal record.
 * @return {{policy: string, allowedDomains: Array<string>}}
 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-set-the-registration-policy-and-approve-registrations-req-isa-004
 */
export function registrationOf(portal) {
	const registration = portal?.authentication?.registration || {}
	const policy = POLICIES.includes(registration.policy) ? registration.policy : 'off'
	const domains = Array.isArray(registration.allowedDomains) ? registration.allowedDomains : []
	return { policy, allowedDomains: domains.map((d) => String(d)) }
}

/**
 * Allowed domains typed one per line (or comma separated), lower case,
 * without a leading `@` and without repeats.
 *
 * @param {string} typed What the administrator typed.
 * @return {Array<string>}
 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-set-the-registration-policy-and-approve-registrations-req-isa-004
 */
export function parseDomains(typed) {
	const domains = []
	for (const part of String(typed || '').split(/[\n,]+/)) {
		const domain = part.trim().toLowerCase().replace(/^@+/, '')
		if (domain !== '' && domains.includes(domain) === false) {
			domains.push(domain)
		}
	}
	return domains
}

/**
 * The portal as it is written back: without the read envelope, every other
 * sign-in setting kept, the registration replaced.
 *
 * @param {object} portal The portal as read.
 * @param {{policy: string, allowedDomains: Array<string>}} choice The new registration.
 * @return {object}
 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-set-the-registration-policy-and-approve-registrations-req-isa-004
 */
export function portalWithRegistration(portal, choice) {
	const body = { ...(portal || {}) }
	delete body['@self']
	body.authentication = {
		...(body.authentication || {}),
		registration: {
			policy: POLICIES.includes(choice?.policy) ? choice.policy : 'off',
			allowedDomains: Array.isArray(choice?.allowedDomains) ? [...choice.allowedDomains] : [],
		},
	}
	return body
}

/**
 * The widget's calls over an injected transport.
 *
 * @param {object} deps Collaborators.
 * @param {(url: string) => Promise<object>} deps.get GETs JSON.
 * @param {(url: string, body: object) => Promise<object>} deps.put PUTs JSON.
 * @param {(url: string, body: object) => Promise<object>} deps.post POSTs JSON.
 * @param {(path: string, params?: object) => string} deps.url Nextcloud's URL generator.
 * @param {(text: string) => string} deps.translate Translator.
 * @return {object} `save`, `waiting`, `approve`, `refuse`.
 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-set-the-registration-policy-and-approve-registrations-req-isa-004
 */
export function createRegistrationSettings({ get, put, post, url, translate }) {
	/**
	 * Answer a decision on one registration.
	 *
	 * @param {object} row The account row.
	 * @param {string} verb `approve` or `refuse`.
	 * @param {object} body Extra fields.
	 * @param {string} done The success sentence.
	 * @return {Promise<{ok: boolean, message: string}>}
	 */
	async function decide(row, verb, body, done) {
		try {
			await post(
				url('/apps/portaliq/api/accounts/{subjectRef}/{verb}', {
					subjectRef: String(row?.subjectRef || ''),
					verb,
				}),
				body,
			)
		} catch (error) {
			const status = error?.response?.status ?? 0
			if (status === 403) {
				return { ok: false, message: translate('You may not decide on registrations. Ask an administrator for this right.') }
			}
			if (error?.response?.data?.error === 'not_pending') {
				return { ok: false, message: translate('Someone already decided on this registration.') }
			}
			return { ok: false, message: translate('The decision could not be saved. Try again.') }
		}
		return { ok: true, message: translate(done) }
	}

	return {
		/**
		 * Save the policy and the allowed domains on the portal.
		 *
		 * @param {string} portalId The portal's id.
		 * @param {{policy: string, allowedDomains: Array<string>}} choice The registration.
		 * @return {Promise<{ok: boolean, message: string}>}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-set-the-registration-policy-and-approve-registrations-req-isa-004
		 */
		async save(portalId, choice) {
			const address = url('/apps/openregister/api/objects/portaliq/portal/{id}', { id: portalId })
			try {
				const { data } = await get(address)
				await put(address, portalWithRegistration(data, choice))
			} catch (error) {
				if (error?.response?.status === 403) {
					return { ok: false, message: translate('Only an administrator can change who may register.') }
				}
				return { ok: false, message: translate('The registration settings could not be saved. Try again.') }
			}
			return { ok: true, message: translate('Your choices are saved.') }
		},

		/**
		 * The self-registrations in this organisation waiting for a decision.
		 *
		 * @param {string} organisation The portal's organisation.
		 * @return {Promise<Array<object>>}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-set-the-registration-policy-and-approve-registrations-req-isa-004
		 */
		async waiting(organisation) {
			if (String(organisation || '') === '') {
				return []
			}
			const query = new URLSearchParams({
				status: 'pending',
				provisionedBy: 'self-registration',
				organisation: String(organisation),
				_limit: '100',
			})
			const { data } = await get(url('/apps/openregister/api/objects/portaliq/portalAccount') + '?' + query.toString())
			const rows = Array.isArray(data) ? data : data?.results
			return Array.isArray(rows) ? rows : []
		},

		/**
		 * Approve one registration: the account becomes active.
		 *
		 * @param {object} row The account row.
		 * @return {Promise<{ok: boolean, message: string}>}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-set-the-registration-policy-and-approve-registrations-req-isa-004
		 */
		async approve(row) {
			return decide(row, 'approve', {}, 'The registration is approved.')
		},

		/**
		 * Refuse one registration, only with a reason.
		 *
		 * @param {object} row The account row.
		 * @param {string} reason Why.
		 * @return {Promise<{ok: boolean, message: string}>}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-set-the-registration-policy-and-approve-registrations-req-isa-004
		 */
		async refuse(row, reason) {
			const why = String(reason || '').trim()
			if (why === '') {
				return { ok: false, message: translate('Give a reason.') }
			}
			return decide(row, 'refuse', { reason: why }, 'The registration is refused.')
		},
	}
}
