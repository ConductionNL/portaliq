/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the change-proposal review surface does, without the surface
 * (change-proposal-queue T06).
 *
 * It reads the queued proposals on the host record, accepts one, asks again
 * when the record moved since the proposal was made, and rejects one only with
 * a reason. Every decision is taken by portaliq's own review routes, which
 * write the record through OpenRegister as the reviewer. The host app is never
 * called.
 *
 * WHY THIS MODULE IMPORTS NOTHING. The GET, the POST and the URL generator are
 * handed in by `ProposalQueueWidget.vue`, so `tests/proposal-queue-leaf.spec.mjs`
 * runs it as a plain node script.
 *
 * @spec openspec/specs/change-proposal-queue/spec.md
 */

/**
 * The HTTP status of a failed request, or 0 when there was none.
 *
 * @param {Error} error The thrown error.
 * @return {number}
 */
function statusOf(error) {
	return Number(error?.response?.status || 0)
}

/**
 * A proposal's id.
 *
 * @param {object} proposal The proposal.
 * @return {string}
 */
function idOf(proposal) {
	return String(proposal?.uuid || proposal?.id || '')
}

/**
 * A proposed or current value as the reviewer reads it.
 *
 * @param {*} value The value.
 * @return {string}
 */
export function formatValue(value) {
	if (value === null || value === undefined || value === '') {
		return '–'
	}
	if (typeof value === 'object') {
		return JSON.stringify(value)
	}
	return String(value)
}

/**
 * A refused decision: drifted (409), with what drifted, or refused with the
 * server's error.
 *
 * @param {object} error The rejected request.
 * @return {object} `{outcome: 'drifted', drift}` or `{outcome: 'refused', error}`.
 */
function refusedDecision(error) {
	const data = error?.response?.data || {}
	if (statusOf(error) === 409 && data.error === 'drifted') {
		return {
			outcome: 'drifted',
			drift: Array.isArray(data.drift) ? data.drift : [],
		}
	}
	return { outcome: 'refused', error: String(data.error || '') }
}

/**
 * The review surface's actions over an injected transport.
 *
 * @param {object} deps The collaborators.
 * @param {Function} deps.get (url, config) => Promise<{data}>
 * @param {Function} deps.post (url, body) => Promise<{data}>
 * @param {Function} deps.url (path, params) => string, path relative to the app
 * @return {object}
 */
export function createProposalQueue({ get, post, url }) {
	/**
	 * Send a decision on one proposal and say how it went.
	 *
	 * @param {object} proposal The proposal.
	 * @param {{path: string, body: object, done: string}} decision The route, the body and the outcome on success.
	 * @return {Promise<object>} `{outcome}`, with `drift` or `error` when refused.
	 */
	async function decide(proposal, decision) {
		try {
			await post(url(decision.path, { id: idOf(proposal) }), decision.body)
			return { outcome: decision.done }
		} catch (error) {
			return refusedDecision(error)
		}
	}

	return {
		/**
		 * The queued proposals on the host record.
		 *
		 * @param {object} host The host context.
		 * @param {string} host.register The record's register.
		 * @param {string} host.schema The record's schema.
		 * @param {string} host.objectId The record.
		 * @return {Promise<{state: string, proposals: Array}>}
		 */
		async load({ register, schema, objectId }) {
			if (!register || !schema || !objectId) {
				return { state: 'empty', proposals: [] }
			}
			try {
				const response = await get(url('/api/proposals', {}), {
					params: { register, schema, id: objectId },
				})
				const proposals = Array.isArray(response?.data?.proposals)
					? response.data.proposals
					: []
				return { state: proposals.length > 0 ? 'ready' : 'empty', proposals }
			} catch (error) {
				return {
					state: statusOf(error) === 403 ? 'forbidden' : 'error',
					proposals: [],
				}
			}
		},

		/**
		 * Accept a proposal. Answers `drifted` with both values when the
		 * record moved since the proposal was made.
		 *
		 * @param {object} proposal The proposal.
		 * @return {Promise<object>}
		 */
		accept(proposal) {
			return decide(proposal, {
				path: '/api/proposals/{id}/accept',
				body: {},
				done: 'accepted',
			})
		},

		/**
		 * Accept a proposal the reviewer was shown the drift on.
		 *
		 * @param {object} proposal The proposal.
		 * @return {Promise<object>}
		 */
		acceptAnyway(proposal) {
			return decide(proposal, {
				path: '/api/proposals/{id}/accept-confirming-drift',
				body: {},
				done: 'accepted',
			})
		},

		/**
		 * Reject a proposal with a reason. Nothing is sent without one.
		 *
		 * @param {object} proposal The proposal.
		 * @param {string} reason Why.
		 * @return {Promise<object>}
		 */
		async reject(proposal, reason) {
			const why = String(reason || '').trim()
			if (why === '') {
				return { outcome: 'reasonRequired' }
			}
			return decide(proposal, {
				path: '/api/proposals/{id}/reject',
				body: { reason: why },
				done: 'rejected',
			})
		},
	}
}
