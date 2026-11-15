/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The request the form binding row actions send ("Which form?" in
 * formBindingPreview.js, "Insert form" in formBindingEmbed.js): the row's
 * binding fields, without the object's own bookkeeping keys, posted to one of
 * the binding routes. Imports nothing, so their tests run as plain node.
 */

/**
 * The binding fields of a row, without the row's metadata.
 *
 * @param {object} row The binding row.
 * @return {object} The binding.
 */
export function bindingOf(row) {
	const binding = {}
	for (const [key, value] of Object.entries(row || {})) {
		if (!key.startsWith('@') && key !== 'id' && key !== 'uuid') {
			binding[key] = value
		}
	}
	return binding
}

/**
 * Post a row's binding to a binding route.
 *
 * @param {{post: (url: string, body: object) => Promise<{data: object}>, generateUrl: (path: string) => string}} deps The POST and Nextcloud's URL generator.
 * @param {{path: string, item: object}} request The route and the row.
 * @return {Promise<object>} The answer's data, or an empty one; rejects when the request fails.
 */
export async function postBinding(deps, request) {
	const response = await deps.post(deps.generateUrl(request.path), {
		binding: bindingOf(request.item),
	})
	return response?.data || {}
}

/**
 * Post a row's binding, and say so when the request fails.
 *
 * @param {{post: (url: string, body: object) => Promise<{data: object}>, generateUrl: (path: string) => string, notifyError: (text: string) => void, translate: (text: string) => string}} deps The POST, the URL generator, the error toast and the translator.
 * @param {{path: string, item: object, failure: (translate: (text: string) => string) => string}} request The route, the row and the sentence for a failed request.
 * @return {Promise<object|null>} The answer's data, or null after a failed request.
 */
export async function postBindingOrSay(deps, request) {
	try {
		return await postBinding(deps, request)
	} catch {
		deps.notifyError(request.failure(deps.translate))
		return null
	}
}
