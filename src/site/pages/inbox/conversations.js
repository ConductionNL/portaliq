// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The plain logic of the messages page per record (site-messages-per-record):
// the tabs ("Alle berichten", "Over Vera"), the cards of the conversations,
// the choices of the "to" field, and the calls to the messaging endpoints
// through the api's one `messaging` door. Its words live here, not in
// ./strings.js, which mirrors the React portal word for word. No Vue, so
// tests/site-messages-per-record.spec.mjs runs it as node.
//
// @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply

/** The words of the messages page per record, in Dutch and English. */
export const conversationStrings = {
	nl: {
		'All messages': 'Alle berichten',
		'About {name}': 'Over {name}',
		'about {name}': 'over {name}',
		'New message': 'Nieuw bericht',
		'A message to school': 'Een bericht aan school',
		To: 'Aan',
		'Choose who you write to': 'Kies aan wie u schrijft',
		'{name}, about {record}': '{name}, over {record}',
		Subject: 'Onderwerp',
		'Subject (optional)': 'Onderwerp (niet verplicht)',
		'Your message': 'Uw bericht',
		'Send message': 'Bericht sturen',
		'Your message has been sent.': 'Uw bericht is verstuurd.',
		'Your message could not be sent. Try again.':
			'Uw bericht kon niet worden verstuurd. Probeer het opnieuw.',
		'Write a message first.': 'Schrijf eerst een bericht.',
		Reply: 'Antwoorden',
		'Your reply': 'Uw antwoord',
		'Send reply': 'Antwoord sturen',
		'Read the whole conversation': 'Lees het hele gesprek',
		'No messages about {name} yet.': 'Nog geen berichten over {name}.',
		New: 'Nieuw',
		You: 'U',
	},
	en: {
		'All messages': 'All messages',
		'About {name}': 'About {name}',
		'about {name}': 'about {name}',
		'New message': 'New message',
		'A message to school': 'A message to school',
		To: 'To',
		'Choose who you write to': 'Choose who you write to',
		'{name}, about {record}': '{name}, about {record}',
		Subject: 'Subject',
		'Subject (optional)': 'Subject (optional)',
		'Your message': 'Your message',
		'Send message': 'Send message',
		'Your message has been sent.': 'Your message has been sent.',
		'Your message could not be sent. Try again.':
			'Your message could not be sent. Try again.',
		'Write a message first.': 'Write a message first.',
		Reply: 'Reply',
		'Your reply': 'Your reply',
		'Send reply': 'Send reply',
		'Read the whole conversation': 'Read the whole conversation',
		'No messages about {name} yet.': 'No messages about {name} yet.',
		New: 'New',
		You: 'You',
	},
}

/**
 * A translator over these words, falling back to the page's own translator.
 *
 * @param {(key: string, vars?: object) => string} fallback The page translator.
 * @param {string} lang `nl` or `en`.
 * @return {(key: string, vars?: object) => string}
 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
 */
export function conversationTranslator(fallback, lang) {
	const own = conversationStrings[lang === 'en' ? 'en' : 'nl']
	return function translate(key, vars = {}) {
		if (!Object.hasOwn(own, key)) {
			return typeof fallback === 'function' ? fallback(key, vars) : key
		}
		let text = own[key]
		for (const [name, value] of Object.entries(vars || {})) {
			text = text.split(`{${name}}`).join(String(value))
		}
		return text
	}
}

/**
 * The short name of a record: the first part of its label ("Vera" of
 * "Vera, Groep 7").
 *
 * @param {string} label The record label.
 * @return {string}
 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
 */
export function shortName(label) {
	return String(label || '')
		.split(',')[0]
		.trim()
}

/**
 * The tabs: "All messages", then one per record that has a conversation or
 * a contact, in the order they first appear (contacts first, so a child
 * without a message yet still has a tab). One record or none: no tabs.
 *
 * @param {Array<object>} threads The threads.
 * @param {Array<object>} contacts The contacts.
 * @param {(key: string, vars?: object) => string} tr The translator.
 * @return {Array<{key: string, label: string, name: string}>}
 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
 */
export function recordTabs(threads, contacts, tr) {
	const seen = new Map()
	for (const item of [...(contacts || []), ...(threads || [])]) {
		const key = String(item?.recordRef || '')
		const name = shortName(item?.recordLabel)
		if (key && name && !seen.has(key)) {
			seen.set(key, name)
		}
	}
	if (seen.size < 2) {
		return []
	}
	return [
		{ key: '', label: tr('All messages'), name: '' },
		...[...seen].map(([key, name]) => ({
			key,
			label: tr('About {name}', { name }),
			name,
		})),
	]
}

/**
 * The threads of one tab, newest first ('' is every thread).
 *
 * @param {Array<object>} threads The threads.
 * @param {string} key The tab's record, '' for all.
 * @return {Array<object>}
 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
 */
export function threadsInTab(threads, key) {
	const moment = (thread) =>
		String(thread?.summary?.lastSentAt || thread?.createdAt || '')
	return (threads || [])
		.filter((thread) => !key || String(thread?.recordRef || '') === key)
		.slice()
		.sort((a, b) => moment(b).localeCompare(moment(a)))
}

/**
 * What one conversation card shows.
 *
 * @param {object} thread The thread with its `summary`.
 * @param {(key: string, vars?: object) => string} tr The translator.
 * @return {{title: string, who: string, initials: string, about: string, when: string, preview: string, isNew: boolean}}
 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
 */
export function threadCard(thread, tr) {
	const fallback =
		thread?.kind === 'group'
			? tr('Group conversation')
			: tr('Conversation with school')
	const who = String(thread?.staffName || '').trim()
	const name = shortName(thread?.recordLabel)
	return {
		title: String(thread?.title || '').trim() || fallback,
		who: who || tr('School'),
		initials: initialsOf(who || tr('School')),
		about: name ? tr('about {name}', { name }) : '',
		when: String(thread?.summary?.lastSentAt || thread?.createdAt || ''),
		preview: String(thread?.summary?.lastBody || ''),
		isNew: Number(thread?.summary?.unread || 0) > 0,
	}
}

/**
 * Two letters for a name's circle: "Meester Daan" reads "MD".
 *
 * @param {string} name The name.
 * @return {string}
 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
 */
export function initialsOf(name) {
	const parts = String(name || '')
		.trim()
		.split(/\s+/)
		.filter(Boolean)
	if (parts.length === 0) {
		return ''
	}
	const first = parts[0][0] || ''
	const last = parts.length > 1 ? parts[parts.length - 1][0] : ''
	return (first + last).toUpperCase()
}

/**
 * The choices of the "to" field: "Meester Daan, about Vera (Groep 7)". The
 * value carries the record and the person; the server proves both again.
 *
 * @param {Array<object>} contacts The contacts.
 * @param {(key: string, vars?: object) => string} tr The translator.
 * @param {string} [onlyRecord] Only the contacts of this record ('' for all).
 * @return {Array<{value: string, label: string, staffRef: string, recordRef: string}>}
 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
 */
export function contactOptions(contacts, tr, onlyRecord = '') {
	return (contacts || [])
		.filter((c) => c?.staffRef && c?.recordRef)
		.filter((c) => !onlyRecord || c.recordRef === onlyRecord)
		.map((c) => {
			const label = String(c.recordLabel || '')
			const name = shortName(label)
			const rest = label
				.split(',')
				.slice(1)
				.map((part) => part.trim())
				.filter(Boolean)
				.join(', ')
			const record = rest ? `${name} (${rest})` : name
			const who = c.role ? `${c.name} (${c.role})` : c.name
			return {
				value: `${c.recordRef}|${c.staffRef}`,
				label: record
					? tr('{name}, about {record}', { name: c.name, record })
					: who,
				staffRef: c.staffRef,
				recordRef: c.recordRef,
			}
		})
}

/**
 * The resident's contacts, or none when the read fails (the page then shows
 * no form, never a broken one).
 *
 * @param {object} api The portal API.
 * @return {Promise<{composeLabel: string, composeHint: string, contacts: Array<object>}>}
 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
 */
export async function fetchContacts(api) {
	const none = { composeLabel: '', composeHint: '', contacts: [] }
	if (typeof api?.messaging !== 'function') {
		return none
	}
	const res = await api.messaging('GET', '/contacts')
	if (!res?.ok || !Array.isArray(res.data?.contacts)) {
		return none
	}
	return {
		composeLabel: String(res.data.composeLabel || ''),
		composeHint: String(res.data.composeHint || ''),
		contacts: res.data.contacts,
	}
}

/**
 * Start a conversation with one contact about one record.
 *
 * @param {object} api The portal API.
 * @param {{staffRef: string, recordRef: string, title?: string, body: string}} message The message.
 * @return {Promise<string|null>} The new thread id, or null when refused.
 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
 */
export async function startConversation(api, message) {
	const res = await api.messaging('POST', '/threads', {
		staffRef: message.staffRef,
		recordRef: message.recordRef,
		title: message.title || '',
		body: message.body,
	})
	return res?.ok && res.data?.id ? String(res.data.id) : null
}

/**
 * Reply in a conversation.
 *
 * @param {object} api The portal API.
 * @param {string} threadId The thread.
 * @param {string} body The reply.
 * @return {Promise<boolean>}
 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
 */
export async function replyTo(api, threadId, body) {
	const res = await api.messaging(
		'POST',
		`/threads/${encodeURIComponent(threadId)}/messages`,
		{ body },
	)
	return Boolean(res?.ok)
}

/**
 * Mark a conversation read once it is opened; a failure changes nothing on
 * screen.
 *
 * @param {object} api The portal API.
 * @param {string} threadId The thread.
 * @return {Promise<void>}
 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
 */
export async function markRead(api, threadId) {
	if (typeof api?.messaging === 'function') {
		await api.messaging(
			'POST',
			`/threads/${encodeURIComponent(threadId)}/read`,
			{},
		)
	}
}
