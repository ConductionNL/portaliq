/**
 * The "Gegevens van de portal" form: which fields it edits, how a value is
 * checked before it is saved, and what is sent.
 *
 * @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
 */

export const KIND_OPTIONS = [
	{ id: 'site', label: 'Website on portaliq' },
	{ id: 'external', label: 'Points to another website' },
]

export const HEADER_OPTIONS = [
	{ id: 'double', label: 'Two lines, menu below' },
	{ id: 'single', label: 'One line' },
]

export const LOCALE_OPTIONS = ['nl', 'en', 'de', 'fr', 'fy']

const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/
const ORIGIN = /^https?:\/\/[a-z0-9.-]+(?::\d+)?$/i

/**
 * Check the form before it is saved.
 *
 * @param {object} form The form values.
 * @return {Record<string, string>} Field to message key; empty when valid.
 * @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
 */
export function validateDetails(form) {
	const errors = {}
	if (!String(form.title || '').trim()) {
		errors.title = 'Give the portal a name.'
	}
	if (!SLUG.test(String(form.slug || ''))) {
		errors.slug = 'Use lowercase letters, digits and dashes.'
	}
	if ((form.locales || []).length === 0) {
		errors.locales = 'Choose at least one language.'
	}
	const bad = (form.frameAncestors || []).find((o) => !ORIGIN.test(String(o)))
	if (bad !== undefined) {
		errors.frameAncestors = 'An address looks like https://www.example.nl.'
	}
	return errors
}

/**
 * What to send: only the fields the beheerder changed. OpenRegister merges a
 * PATCH into the stored portal, so every field the form does not edit (domains,
 * theme, tokens, traffic, bookkeeping) is never part of the request and cannot
 * be lost by it.
 *
 * @param {object} portal The portal as the page loaded it.
 * @param {object} form The form values.
 * @return {object} The body to PATCH; empty when nothing changed.
 * @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
 */
export function portalPatch(portal, form) {
	const before = formFrom(portal)
	const after = {
		title: String(form.title).trim(),
		tagline: String(form.tagline || '').trim(),
		slug: form.slug,
		organisation: String(form.organisation || '').trim(),
		kind: form.kind,
		headerVariant: form.headerVariant,
		locales: [...form.locales],
		accountLabel: String(form.accountLabel || '').trim(),
		logo: String(form.logo || '').trim(),
		frameAncestors: [...(form.frameAncestors || [])],
	}
	const patch = {}
	for (const [key, value] of Object.entries(after)) {
		if (JSON.stringify(value) !== JSON.stringify(before[key])) {
			patch[key] = value
		}
	}
	return patch
}

/**
 * The portal as the server holds it after a PATCH: the baseline the next save
 * compares against.
 *
 * @param {object} baseline The portal before the save.
 * @param {object} patch The body that was sent.
 * @return {object} The portal with the patch applied.
 * @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
 */
export function afterSave(baseline, patch) {
	return { ...baseline, ...patch }
}

/**
 * The form values for a portal.
 *
 * @param {object} portal The portal as the page loaded it.
 * @return {object} The form values.
 * @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
 */
export function formFrom(portal) {
	const p = portal || {}
	return {
		title: p.title || '',
		tagline: p.tagline || '',
		slug: p.slug || '',
		organisation: p.organisation || '',
		kind: p.kind || 'site',
		headerVariant: p.headerVariant || 'double',
		locales: Array.isArray(p.locales) && p.locales.length > 0 ? [...p.locales] : ['nl'],
		accountLabel: p.accountLabel || '',
		logo: p.logo || '',
		frameAncestors: Array.isArray(p.frameAncestors) ? [...p.frameAncestors] : [],
	}
}
