/**
 * The "Domeinen" and "Beheerders" cards of a portal's Instellingen tab: what a
 * valid host name and editor look like, how a certificate end date reads, and
 * which entries a portal has after an add or a remove.
 *
 * @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
 */

export const ROLE_OPTIONS = [
	{ id: 'chief-editor', label: 'Chief editor' },
	{ id: 'editor', label: 'Editor' },
]

const HOST = /^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i

/**
 * Check a host name before it is added: a plain host name, no scheme, port or path.
 *
 * @param {string} value What the beheerder typed.
 * @param {Array<{hostname: string}>} existing The domains the portal already has.
 * @return {string} A message key, or an empty string when the host name is fine.
 * @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
 */
export function hostnameError(value, existing = []) {
	const host = String(value || '').trim().toLowerCase()
	if (!HOST.test(host)) {
		return 'Use a plain host name, such as portal.example.nl, without https:// or a path.'
	}
	if (existing.some((d) => String(d.hostname || '').toLowerCase() === host)) {
		return 'This domain is already listed.'
	}
	return ''
}

/**
 * The domains with one more, waiting for verification.
 *
 * @param {Array<object>} domains The portal's domains.
 * @param {string} value The host name to add.
 * @return {Array<object>} The new list.
 * @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
 */
export function withDomain(domains, value) {
	return [...(domains || []), { hostname: String(value).trim().toLowerCase(), verified: false }]
}

/**
 * How a domain's certificate reads.
 *
 * @param {object} domain The domain entry, with `certificateValidUntil` when probed.
 * @param {(date: Date) => string} format Formats a date, in the viewer's language.
 * @return {string|null} The end date formatted, or null when there is none.
 * @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
 */
export function certificateEnd(domain, format) {
	const until = domain?.certificateValidUntil
	if (!until || Number.isNaN(new Date(until).getTime())) {
		return null
	}
	return format(new Date(until))
}

/**
 * Check an editor before it is added.
 *
 * @param {object} editor The values typed: userId, displayName, role.
 * @param {Array<{userId: string}>} existing The editors the portal already has.
 * @return {string} A message key, or an empty string when the editor is fine.
 * @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
 */
export function editorError(editor, existing = []) {
	const userId = String(editor?.userId || '').trim()
	if (userId === '') {
		return 'Give the user name of the editor.'
	}
	if (existing.some((e) => e.userId === userId)) {
		return 'This user is already listed.'
	}
	return ''
}

/**
 * The editors with one more.
 *
 * @param {Array<object>} editors The portal's editors.
 * @param {object} editor The values typed.
 * @return {Array<object>} The new list.
 * @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
 */
export function withEditor(editors, editor) {
	const userId = String(editor.userId).trim()
	return [
		...(editors || []),
		{
			userId,
			displayName: String(editor.displayName || '').trim() || userId,
			role: editor.role === 'chief-editor' ? 'chief-editor' : 'editor',
		},
	]
}

/**
 * The initials of a name, for the avatar of a side card row.
 *
 * @param {string} name The display name.
 * @return {string} Up to two capital letters.
 * @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
 */
export function initials(name) {
	return String(name || '')
		.split(/\s+/)
		.filter(Boolean)
		.slice(0, 2)
		.map((w) => w[0].toUpperCase())
		.join('')
}
