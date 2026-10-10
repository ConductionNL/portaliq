/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The portal's identity widget, without the widget
 * (portal-identity-from-the-admin REQ-PIA-001, REQ-PIA-003).
 *
 * An administrator picks the favicon, the logo and the hero image from the
 * portal's media library, and the kind of organisation from the TOOI list in
 * OpenRegister's concept register. The portal stores media:<id> references
 * and the picked uri with its label. The save goes through OpenRegister's
 * object API, where PortalIdentityGuardListener refuses a favicon that is not
 * a PNG, SVG or ICO and media of another portal; its message is shown as is.
 *
 * The transport is handed in by `src/widgets/PortalIdentity.vue`, so
 * `tests/portal-identity.spec.mjs` runs this as a plain node script.
 *
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portals-favicon-logo-and-hero-image-come-from-the-media-library-req-pia-001
 */

import { createMediaLibrary } from './mediaLibrary.js'

/**
 * The three image fields, in the order the widget shows them.
 *
 * @type {Array<string>}
 */
export const IMAGE_FIELDS = ['favicon', 'logo', 'heroImage']

const PREFIX = 'media:'

/**
 * The media id a stored value refers to, or ''.
 *
 * @param {*} value The stored value.
 * @return {string}
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portals-favicon-logo-and-hero-image-come-from-the-media-library-req-pia-001
 */
export function mediaIdOf(value) {
	return typeof value === 'string' && value.startsWith(PREFIX)
		? value.slice(PREFIX.length)
		: ''
}

/**
 * The widget's starting choice, read from the stored portal.
 *
 * @param {object} portal The portal object.
 * @return {object} `{favicon, logo, heroImage, organisationType}` as media ids and a uri.
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portals-favicon-logo-and-hero-image-come-from-the-media-library-req-pia-001
 */
export function choiceOf(portal) {
	const choice = {}
	for (const field of IMAGE_FIELDS) {
		choice[field] = mediaIdOf(portal?.[field])
	}
	choice.organisationType = String(portal?.organisationType || '')
	return choice
}

/**
 * The portal to write: the picked images as media references, and the kind
 * of organisation with its label. Only a kind from the offered list is
 * stored; no free text. When the list is not installed the stored kind is
 * kept as it is. A logo given as a web address stays when no image is picked.
 *
 * @param {object} portal The portal as read.
 * @param {object} choice The widget's choice (see choiceOf()).
 * @param {{installed: boolean, options: Array<{uri: string, label: string}>}} types The offered kinds.
 * @return {object}
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
 */
export function portalWithIdentity(portal, choice, types) {
	const next = { ...portal }
	for (const field of IMAGE_FIELDS) {
		const id = String(choice?.[field] || '')
		if (id !== '') {
			next[field] = PREFIX + id
		} else if (field !== 'logo' || mediaIdOf(next[field]) !== '') {
			delete next[field]
		}
	}

	if (types?.installed) {
		const uri = String(choice?.organisationType || '')
		const picked = (types.options || []).find((option) => option.uri === uri)
		if (picked) {
			next.organisationType = picked.uri
			next.organisationTypeLabel = picked.label
		} else {
			delete next.organisationType
			delete next.organisationTypeLabel
		}
	}

	return next
}

/**
 * The widget's reads and its save over an injected transport.
 *
 * @param {object} deps The collaborators.
 * @param {Function} deps.get path => Promise<{data}>, the path relative to the Nextcloud root
 * @param {Function} deps.put (path, body) => Promise
 * @param {Function} deps.url (path, params) => string, a portaliq route
 * @param {Function} deps.ocUrl (path, params) => string, an address on the Nextcloud root
 * @param {Function} deps.translate key => string
 * @return {object}
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portals-favicon-logo-and-hero-image-come-from-the-media-library-req-pia-001
 */
export function createPortalIdentity({ get, put, url, ocUrl, translate }) {
	const library = createMediaLibrary({ get: (path) => get(ocUrl(path)) })

	return {
		/**
		 * The portal's published images.
		 *
		 * @param {string} slug The portal slug.
		 * @return {Promise<{state: string, items: Array}>}
		 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portals-favicon-logo-and-hero-image-come-from-the-media-library-req-pia-001
		 */
		async images(slug) {
			const loaded = await library.load(slug)
			return {
				state: loaded.state,
				items: loaded.items.filter((item) => item.kind === 'image'),
			}
		},

		/**
		 * The kinds of organisation on offer.
		 *
		 * @return {Promise<{installed: boolean, options: Array}>}
		 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
		 */
		async types() {
			try {
				const { data } = await get(url('/api/organisation-types'))
				return {
					installed: data?.installed === true,
					options: Array.isArray(data?.options) ? data.options : [],
				}
			} catch {
				return { installed: false, options: [] }
			}
		},

		/**
		 * Save the choice on the portal.
		 *
		 * @param {string} portalId The portal's id.
		 * @param {object} choice The widget's choice.
		 * @param {object} types The offered kinds.
		 * @return {Promise<{ok: boolean, message: string}>}
		 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portals-favicon-logo-and-hero-image-come-from-the-media-library-req-pia-001
		 */
		async save(portalId, choice, types) {
			const address = ocUrl(
				'/apps/openregister/api/objects/portaliq/portal/{id}',
				{ id: portalId },
			)
			try {
				const { data } = await get(address)
				await put(address, portalWithIdentity(data, choice, types))
			} catch (error) {
				const refusal = error?.response?.data?.message
				return {
					ok: false,
					message:
						typeof refusal === 'string' && refusal !== ''
							? refusal
							: translate('The portal could not be saved. Try again.'),
				}
			}
			return { ok: true, message: translate('Your choices are saved.') }
		},
	}
}
