/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `New portal` on the Portals page (operate-portals-per-organisation T07):
 * the same provisioning as `occ portaliq:portal:provision`, a draft portal
 * for an organisation with its host, a menu and a home page, through
 * PortalProvisionController, instance administrators only. Not the generic
 * object form: that would skip the slug and host checks, the menu and home
 * page, and the DNS record that verifies the host.
 *
 * WHY THIS MODULE IMPORTS NOTHING. The POST, the URL generator and the
 * translator are handed in by `src/customComponents.js`, so
 * `tests/portal-provisioning.spec.mjs` runs it as a plain node script.
 *
 * @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md#requirement-a-hosting-party-can-add-an-organisations-portal-in-one-step-req-opo-004
 */

/**
 * The sentence for a refusal, by the server's error code.
 */
const REFUSALS = {
	invalid:
		'Give the organisation, a title, a slug of lower case letters, digits and hyphens, and a web address without https:// or a port.',
	slug_taken: 'This portal slug is already used by another portal.',
	host_taken: 'This web address is already used by another portal.',
	unavailable: 'OpenRegister is not available, so nothing was written.',
}

/**
 * A trimmed string.
 *
 * @param {unknown} value The value.
 * @return {string}
 */
function text(value) {
	return String(value ?? '').trim()
}

/**
 * The host as the server wants it: no scheme, no path, no port, lower case.
 *
 * @param {unknown} value What was typed.
 * @return {string}
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t07
 */
export function hostOf(value) {
	return text(value)
		.toLowerCase()
		.replace(/^[a-z]+:\/\//, '')
		.replace(/[/?#].*$/, '')
		.replace(/:\d+$/, '')
}

/**
 * What the provision route is sent for the dialog's fields.
 *
 * @param {object|null} fields The dialog's fields.
 * @return {{organisation: string, slug: string, title: string, host: string}}
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t07
 */
export function provisionBody(fields) {
	return {
		organisation: text(fields?.organisation),
		slug: text(fields?.slug).toLowerCase(),
		title: text(fields?.title),
		host: hostOf(fields?.host),
	}
}

/**
 * What the dialog still lacks before it may be sent, in field order; '' when nothing.
 *
 * @param {object} body The provision body.
 * @return {string} The English source sentence, or ''.
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t07
 */
export function missingForProvision(body) {
	if (body.organisation === '') {
		return 'Give the organisation the portal belongs to.'
	}
	if (body.title === '') {
		return 'Give the portal a title.'
	}
	if (!/^[a-z0-9][a-z0-9-]{1,62}$/.test(body.slug)) {
		return 'Give a slug of lower case letters, digits and hyphens, for example zeist.'
	}
	if (body.host === '') {
		return 'Give the web address the portal answers on, for example mijn.zeist.nl.'
	}
	return ''
}

/**
 * A slug suggested from a title: lower case, hyphens for anything else.
 *
 * @param {unknown} title The title.
 * @return {string}
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t07
 */
export function slugFromTitle(title) {
	return text(title)
		.toLowerCase()
		.normalize('NFD')
		.replace(/[̀-ͯ]/g, '')
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-+|-+$/g, '')
		.slice(0, 63)
}

/**
 * The call behind the dialog.
 *
 * @param {object} deps Collaborators.
 * @param {(url: string, body: object) => Promise<object>} deps.post POSTs JSON; rejects with `{response: {status, data}}` on a non-2xx.
 * @param {(path: string) => string} deps.generateUrl Nextcloud's URL generator.
 * @param {(text: string, vars?: object) => string} deps.translate Translator.
 * @return {{provision: (fields: object) => Promise<{ok: boolean, message: string, dns?: {name: string, value: string}}>}}
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t07
 */
export function createPortalProvisioning({ post, generateUrl, translate }) {
	return {
		/**
		 * Provision a portal for the dialog's fields.
		 *
		 * @param {object} fields The dialog's fields.
		 * @return {Promise<{ok: boolean, message: string, dns?: {name: string, value: string}}>}
		 */
		async provision(fields) {
			const body = provisionBody(fields)
			const missing = missingForProvision(body)
			if (missing !== '') {
				return { ok: false, message: translate(missing) }
			}
			try {
				const response = await post(generateUrl('/apps/portaliq/api/portals/provision'), body)
				const dns = response?.data?.dns
				return {
					ok: true,
					message: translate('The draft portal {title} is created. It is not published.', { title: body.title }),
					dns: dns && typeof dns === 'object' ? { name: String(dns.name ?? ''), value: String(dns.value ?? '') } : undefined,
				}
			} catch (error) {
				const status = error?.response?.status ?? 0
				if (status === 403) {
					return { ok: false, message: translate('Only an instance administrator can add a portal.') }
				}
				const code = String(error?.response?.data?.error || '')
				return { ok: false, message: translate(REFUSALS[code] || 'The portal could not be written. Nothing else was created.') }
			}
		},
	}
}

/**
 * The `newPortal` header handler.
 *
 * @param {object} deps Collaborators.
 * @param {object} deps.provisioning `createPortalProvisioning()`.
 * @param {(submit: (fields: object) => Promise<object>) => Promise<string|null>} deps.openDialog Opens NewPortalDialog; resolves its closing sentence, or null.
 * @param {(message: string) => void} deps.notify Shows a success.
 * @param {() => void} deps.reload Shows the new portal in the list.
 * @return {{newPortal: () => Promise<boolean>}}
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t07
 */
export function createPortalProvisioningHandlers({ provisioning, openDialog, notify, reload }) {
	return {
		/**
		 * `New portal` on the Portals page.
		 *
		 * @return {Promise<boolean>} Whether a portal was created.
		 */
		async newPortal() {
			const message = await openDialog((fields) => provisioning.provision(fields))
			if (typeof message !== 'string' || message === '') {
				return false
			}
			notify(message)
			reload()
			return true
		},
	}
}
