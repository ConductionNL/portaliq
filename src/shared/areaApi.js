// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The calls of the resident's own area pages that the site entry has no room
// for: contacts (own-contacts-and-invitations) and plans
// (shared-plans-with-a-caseworker). They sit on the portal api's `request`
// and load with the pages that use them.

/**
 * The contact calls over a portal api.
 *
 * @param {{request: Function}} api The portal api.
 * @return {{fetchContacts: Function, contactAction: Function}} The calls.
 *
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t06
 */
export function contactsApi(api) {
	return {
		/**
		 * The resident's own contacts, what waits for an answer, and the counts per role.
		 *
		 * @return {Promise<{incoming: Array<object>, outgoing: Array<object>, contacts: Array<object>, counts: object}|null>} Null when refused.
		 */
		async fetchContacts() {
			const answer = await api.request('GET', '/contacts')
			return answer.ok ? answer.json : null
		},

		/**
		 * Do one thing to the contacts: invite, answer, send again, take back,
		 * remove, or hand back the secret of an invitation link.
		 *
		 * @param {'invite'|'respond'|'resend'|'withdraw'|'remove'|'accept'} action What to do.
		 * @param {object} [args] `email` and `message` to invite, `id` for the rest, `accept` for respond, `token` to accept a link.
		 * @return {Promise<{ok: boolean, status: number, error: string}>} The outcome; a refusal names its reason in `error`.
		 */
		async contactAction(action, args = {}) {
			const id = encodeURIComponent(args.id || '')
			const calls = {
				invite: ['POST', '/contacts/invite', { email: args.email, message: args.message }],
				accept: ['POST', '/contacts/accept-invitation', { token: args.token }],
				respond: ['POST', `/contacts/${id}/respond`, { accept: args.accept === true }],
				resend: ['POST', `/contacts/${id}/resend`, {}],
				withdraw: ['POST', `/contacts/${id}/withdraw`, {}],
				remove: ['DELETE', `/contacts/${id}`, null],
			}
			const call = calls[action]
			if (!call) {
				return { ok: false, status: 0, error: 'unknown' }
			}
			const answer = await api.request(call[0], call[1], call[2])
			return { ok: answer.ok, status: answer.status, error: String(answer.json?.error || '') }
		},
	}
}

/**
 * The plan calls over a portal api.
 *
 * @param {{request: Function}} api The portal api.
 * @return {object} The calls.
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
 */
export function plansApi(api) {
	return {
		/**
		 * The resident's plans with counts, or null when refused.
		 *
		 * @return {Promise<{plans: Array<object>, counts: object}|null>} The plans.
		 */
		async fetchPlans() {
			const answer = await api.request('GET', '/plans')
			return answer.ok ? answer.json : null
		},

		/**
		 * One plan with its actions, or null for a plan the resident is not part of.
		 *
		 * @param {string} id The plan.
		 * @return {Promise<object|null>} The plan.
		 */
		async fetchPlan(id) {
			const answer = await api.request('GET', `/plans/${encodeURIComponent(id)}`)
			return answer.ok ? answer.json : null
		},

		/**
		 * The templates a plan can start from.
		 *
		 * @return {Promise<Array<object>>} The templates, or `[]`.
		 */
		async fetchPlanTemplates() {
			const answer = await api.request('GET', '/plans/templates')
			return answer.ok && Array.isArray(answer.json?.templates) ? answer.json.templates : []
		},

		/**
		 * Do one thing to the plans.
		 *
		 * @param {'start'|'update'|'delete'|'addParticipants'|'removeParticipant'|'addAction'|'updateAction'} action What to do.
		 * @param {object} [args] `id` of the plan, `actionId`, `ref`, and the fields to send as `data`.
		 * @return {Promise<{ok: boolean, status: number, error: string, id: string}>} The outcome; `id` names a plan just started.
		 */
		async planAction(action, args = {}) {
			const id = encodeURIComponent(args.id || '')
			const calls = {
				start: ['POST', '/plans', args.data || {}],
				update: ['PATCH', `/plans/${id}`, args.data || {}],
				delete: ['DELETE', `/plans/${id}`, null],
				addParticipants: ['POST', `/plans/${id}/participants`, args.data || {}],
				removeParticipant: ['DELETE', `/plans/${id}/participants/${encodeURIComponent(args.ref || '')}`, null],
				addAction: ['POST', `/plans/${id}/actions`, args.data || {}],
				updateAction: ['PATCH', `/plans/${id}/actions/${encodeURIComponent(args.actionId || '')}`, args.data || {}],
			}
			const call = calls[action]
			if (!call) {
				return { ok: false, status: 0, error: 'unknown', id: '' }
			}
			const answer = await api.request(call[0], call[1], call[2])
			return { ok: answer.ok, status: answer.status, error: String(answer.json?.error || ''), id: String(answer.json?.id || '') }
		},

		/**
		 * Save a plan as a PDF (the goal, actions, participants and note).
		 *
		 * @param {string} id The plan.
		 * @param {string} name The file's name.
		 * @return {Promise<{ok: boolean, status: number}>} The outcome.
		 */
		async downloadPlanPdf(id, name = 'plan') {
			const answer = await api.request('GET', `/plans/${encodeURIComponent(id)}/pdf`, null, true)
			if (!answer.ok) {
				return { ok: false, status: answer.status }
			}
			const objectUrl = window.URL.createObjectURL(await answer.res.blob())
			const link = document.createElement('a')
			link.href = objectUrl
			link.download = `${name.toLowerCase().replace(/[^a-z0-9]+/g, '-') || 'plan'}.pdf`
			document.body.appendChild(link)
			link.click()
			setTimeout(() => {
				link.remove()
				window.URL.revokeObjectURL(objectUrl)
			}, 10000)
			return { ok: true, status: answer.status }
		},
	}
}
