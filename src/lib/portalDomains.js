/**
 * The side cards of a portal page ("Domein en certificaat", "Beheerders"): how a
 * certificate end date reads and the initials of a name. The Domeinen and
 * Beheerders sections of the Instellingen tab are `field-list` widgets in the
 * manifest (PortalDetail, portal-domains and portal-editors).
 *
 * @spec openspec/specs/portal-settings/spec.md
 */

/**
 * How a domain's certificate reads.
 *
 * @param {object} domain The domain entry, with `certificateValidUntil` when probed.
 * @param {(date: Date) => string} format Formats a date, in the viewer's language.
 * @return {string|null} The end date formatted, or null when there is none.
 * @spec openspec/specs/portal-settings/spec.md
 */
export function certificateEnd(domain, format) {
	const until = domain?.certificateValidUntil
	if (!until || Number.isNaN(new Date(until).getTime())) {
		return null
	}
	return format(new Date(until))
}

/**
 * The initials of a name, for the avatar of a side card row.
 *
 * @param {string} name The display name.
 * @return {string} Up to two capital letters.
 * @spec openspec/specs/portal-settings/spec.md
 */
export function initials(name) {
	return String(name || '')
		.split(/\s+/)
		.filter(Boolean)
		.slice(0, 2)
		.map((w) => w[0].toUpperCase())
		.join('')
}
