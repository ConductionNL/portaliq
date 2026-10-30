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
				invite: [
					'POST',
					'/contacts/invite',
					{ email: args.email, message: args.message },
				],
				accept: [
					'POST',
					'/contacts/accept-invitation',
					{ token: args.token },
				],
				respond: [
					'POST',
					`/contacts/${id}/respond`,
					{ accept: args.accept === true },
				],
				resend: ['POST', `/contacts/${id}/resend`, {}],
				withdraw: ['POST', `/contacts/${id}/withdraw`, {}],
				remove: ['DELETE', `/contacts/${id}`, null],
			}
			const call = calls[action]
			if (!call) {
				return { ok: false, status: 0, error: 'unknown' }
			}
			const answer = await api.request(call[0], call[1], call[2])
			return {
				ok: answer.ok,
				status: answer.status,
				error: String(answer.json?.error || ''),
			}
		},

		/**
		 * Start a conversation with one approved contact: the first message
		 * goes with it. The server proves the contact from the resident's own
		 * row (own-contacts-and-invitations T07).
		 *
		 * @param {string} contactId The resident's own contact row.
		 * @param {string} body The first message.
		 * @return {Promise<string|null>} The new conversation's id, or null when refused.
		 */
		async sendMessage(contactId, body) {
			if (typeof api.messaging !== 'function') {
				return null
			}
			const res = await api.messaging('POST', '/threads', { contactId, body })
			return res?.ok && res.data?.id ? String(res.data.id) : null
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
			const answer = await api.request(
				'GET',
				`/plans/${encodeURIComponent(id)}`,
			)
			return answer.ok ? answer.json : null
		},

		/**
		 * The templates a plan can start from.
		 *
		 * @return {Promise<Array<object>>} The templates, or `[]`.
		 */
		async fetchPlanTemplates() {
			const answer = await api.request('GET', '/plans/templates')
			return answer.ok && Array.isArray(answer.json?.templates)
				? answer.json.templates
				: []
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
				addParticipants: [
					'POST',
					`/plans/${id}/participants`,
					args.data || {},
				],
				removeParticipant: [
					'DELETE',
					`/plans/${id}/participants/${encodeURIComponent(args.ref || '')}`,
					null,
				],
				addAction: ['POST', `/plans/${id}/actions`, args.data || {}],
				updateAction: [
					'PATCH',
					`/plans/${id}/actions/${encodeURIComponent(args.actionId || '')}`,
					args.data || {},
				],
			}
			const call = calls[action]
			if (!call) {
				return { ok: false, status: 0, error: 'unknown', id: '' }
			}
			const answer = await api.request(call[0], call[1], call[2])
			return {
				ok: answer.ok,
				status: answer.status,
				error: String(answer.json?.error || ''),
				id: String(answer.json?.id || ''),
			}
		},

		/**
		 * Save a plan as a PDF (the goal, actions, participants and note).
		 *
		 * @param {string} id The plan.
		 * @param {string} name The file's name.
		 * @return {Promise<{ok: boolean, status: number}>} The outcome.
		 */
		async downloadPlanPdf(id, name = 'plan') {
			const answer = await api.request(
				'GET',
				`/plans/${encodeURIComponent(id)}/pdf`,
				null,
				true,
			)
			if (!answer.ok) {
				return { ok: false, status: answer.status }
			}
			await saveBlob(
				answer.res,
				`${name.toLowerCase().replace(/[^a-z0-9]+/g, '-') || 'plan'}.pdf`,
			)
			return { ok: true, status: answer.status }
		},

		/**
		 * Add a file to a plan, or a new version of a file it holds.
		 *
		 * @param {string} id The plan.
		 * @param {File} file The file.
		 * @param {string} [previousVersion] The file this one is a new version of.
		 * @return {Promise<{ok: boolean, status: number, error: string}>} The outcome.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
		 */
		async addPlanFile(id, file, previousVersion = '') {
			const form = new FormData()
			form.append('file', file)
			if (previousVersion) {
				form.append('previousVersion', previousVersion)
			}
			const answer = await api.request(
				'POST',
				`/plans/${encodeURIComponent(id)}/files`,
				form,
			)
			return {
				ok: answer.ok,
				status: answer.status,
				error: String(answer.json?.error || ''),
			}
		},

		/**
		 * Save one file of a plan.
		 *
		 * @param {string} id The plan.
		 * @param {{id: string, name: string}} file The file.
		 * @return {Promise<{ok: boolean, status: number}>} The outcome.
		 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
		 */
		async downloadPlanFile(id, file) {
			const answer = await api.request(
				'GET',
				`/plans/${encodeURIComponent(id)}/files/${encodeURIComponent(file.id)}`,
				null,
				true,
			)
			if (!answer.ok) {
				return { ok: false, status: answer.status }
			}
			await saveBlob(answer.res, file.name || 'bestand')
			return { ok: true, status: answer.status }
		},
	}
}

/**
 * The calls of Mijn acties and the action dialog (personal-action-list). An
 * action read through a plan names the plan in `plan`.
 *
 * @param {{request: Function}} api The portal api.
 * @return {object} The calls.
 *
 * @spec openspec/changes/personal-action-list/tasks.md#t03
 */
export function actionsApi(api) {
	const path = (id, rest = '', plan = '') => {
		const query = plan ? `?plan=${encodeURIComponent(plan)}` : ''
		return `/my-actions${id ? `/${encodeURIComponent(id)}` : ''}${rest}${query}`
	}
	const outcome = (answer) => ({
		ok: answer.ok,
		status: answer.status,
		error: String(answer.json?.error || ''),
		id: String(answer.json?.id || ''),
	})
	return {
		/**
		 * The resident's actions, or null when refused.
		 *
		 * @return {Promise<{actions: Array<object>, done: number, total: number, assignees: Array<object>}|null>} The list.
		 */
		async fetchActions() {
			const answer = await api.request('GET', path())
			return answer.ok ? answer.json : null
		},

		/**
		 * Add an action to the resident's own list.
		 *
		 * @param {object} data The fields.
		 * @return {Promise<{ok: boolean, status: number, error: string, id: string}>} The outcome.
		 */
		async createAction(data) {
			return outcome(await api.request('POST', path(), data))
		},

		/**
		 * Change an action.
		 *
		 * @param {string} id The action.
		 * @param {object} data The fields to change.
		 * @param {string} [plan] The plan it is in.
		 * @return {Promise<{ok: boolean, status: number, error: string}>} The outcome.
		 */
		async updateAction(id, data, plan = '') {
			return outcome(await api.request('PATCH', path(id, '', plan), data))
		},

		/**
		 * Delete an action.
		 *
		 * @param {string} id The action.
		 * @param {string} [plan] The plan it is in.
		 * @return {Promise<{ok: boolean, status: number, error: string}>} The outcome.
		 */
		async deleteAction(id, plan = '') {
			return outcome(await api.request('DELETE', path(id, '', plan), null))
		},

		/**
		 * The history of an action, newest first, or null when refused.
		 *
		 * @param {string} id The action.
		 * @param {string} [plan] The plan it is in.
		 * @return {Promise<Array<object>|null>} The lines.
		 */
		async fetchHistory(id, plan = '') {
			const answer = await api.request('GET', path(id, '/history', plan))
			return answer.ok && Array.isArray(answer.json?.history)
				? answer.json.history
				: null
		},

		/**
		 * Keep one file with an action, replacing the one it had.
		 *
		 * @param {string} id The action.
		 * @param {File} file The file.
		 * @param {string} [plan] The plan it is in.
		 * @return {Promise<{ok: boolean, status: number, error: string}>} The outcome.
		 */
		async attachActionFile(id, file, plan = '') {
			const form = new FormData()
			form.append('file', file)
			return outcome(await api.request('POST', path(id, '/file', plan), form))
		},

		/**
		 * Save the action's file.
		 *
		 * @param {string} id The action.
		 * @param {string} name The file's name.
		 * @param {string} [plan] The plan it is in.
		 * @return {Promise<{ok: boolean, status: number}>} The outcome.
		 */
		async downloadActionFile(id, name, plan = '') {
			const answer = await api.request(
				'GET',
				path(id, '/file', plan),
				null,
				true,
			)
			if (!answer.ok) {
				return { ok: false, status: answer.status }
			}
			await saveBlob(answer.res, name || 'bestand')
			return { ok: true, status: answer.status }
		},
	}
}

/**
 * Hand a response's body to the browser as a download.
 *
 * @param {Response} res The response.
 * @param {string} name The file's name.
 * @return {Promise<void>} Resolves when the download started.
 */
async function saveBlob(res, name) {
	const objectUrl = window.URL.createObjectURL(await res.blob())
	const link = document.createElement('a')
	link.href = objectUrl
	link.download = name
	document.body.appendChild(link)
	link.click()
	setTimeout(() => {
		link.remove()
		window.URL.revokeObjectURL(objectUrl)
	}, 10000)
}

/**
 * The mandate calls over a portal api (site-mandates-the-represented-manage
 * T4): who may act for the session's party, and the mandates it holds. The
 * party always comes from the session on the server, never from here.
 *
 * @param {{request: Function}} api The portal api.
 * @return {object} The calls.
 *
 * @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4
 */
export function mandatesApi(api) {
	const answerOf = (answer) => ({
		ok: answer.ok,
		status: answer.status,
		error: String(answer.json?.error || ''),
	})
	return {
		/**
		 * Mandates and open invitations given on the party's behalf.
		 *
		 * @return {Promise<Array<object>|null>} The items, or null when the session may not manage (403) or the read failed.
		 */
		async given() {
			const answer = await api.request('GET', '/mandates/given')
			return answer.ok && Array.isArray(answer.json?.items)
				? answer.json.items
				: null
		},

		/**
		 * The mandates the session holds.
		 *
		 * @return {Promise<Array<object>>} The items; none when refused.
		 */
		async held() {
			const answer = await api.request('GET', '/mandates/held')
			return answer.ok && Array.isArray(answer.json?.items)
				? answer.json.items
				: []
		},

		/**
		 * The case types a mandate may be narrowed to, of the visited portal.
		 *
		 * @param {string} portal The portal slug.
		 * @return {Promise<Array<{id: string, title: string}>>} The choices.
		 */
		async caseTypes(portal) {
			const answer = await api.request(
				'GET',
				`/mandates/case-types?portal=${encodeURIComponent(portal || '')}`,
			)
			return answer.ok && Array.isArray(answer.json?.items)
				? answer.json.items
				: []
		},

		/**
		 * Invite an address. The link goes to that mailbox only.
		 *
		 * @param {{email: string, caseTypes: Array<string>, expiresAt: string}} terms The terms.
		 * @return {Promise<{ok: boolean, status: number, error: string}>} The outcome.
		 */
		async invite(terms) {
			return answerOf(
				await api.request('POST', '/mandates/invitations', {
					email: terms.email,
					caseTypes: terms.caseTypes,
					label: '',
					expiresAt: terms.expiresAt,
				}),
			)
		},

		/**
		 * End one item of the given list: revoke a mandate, or take back an invitation.
		 *
		 * @param {{kind: string, id: string}} item The item.
		 * @return {Promise<{ok: boolean, status: number, error: string}>} The outcome.
		 */
		async end(item) {
			const id = encodeURIComponent(item.id || '')
			const call =
				item.kind === 'invitation'
					? ['DELETE', `/mandates/invitations/${id}`, null]
					: ['POST', `/mandates/${id}/revoke`, {}]
			return answerOf(await api.request(call[0], call[1], call[2]))
		},

		/**
		 * Stop a mandate the session holds.
		 *
		 * @param {string} id The mandate.
		 * @return {Promise<{ok: boolean, status: number, error: string}>} The outcome.
		 */
		async stop(id) {
			return answerOf(
				await api.request(
					'POST',
					`/mandates/held/${encodeURIComponent(id || '')}/stop`,
					{},
				),
			)
		},
	}
}
