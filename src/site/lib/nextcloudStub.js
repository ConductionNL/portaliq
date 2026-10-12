// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// What the public bundles get in place of @nextcloud/capabilities, /router and
// /auth when they import nextcloud-vue's `visibleWhen.js` (webpack.site.js,
// NormalModuleReplacementPlugin). The local condition check they use never
// reaches these: it answers on the answers typed, not on an installed app or a
// signed-in user. The stub keeps a public origin free of the Nextcloud client
// packages and the embed frame inside its size budget.
//
// @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-a-fields-condition-decides-whether-the-resident-sees-it-req-icq-001

export const getCapabilities = () => ({})
export const generateUrl = (path) => String(path)
export const getCurrentUser = () => null
export const generateOcsUrl = (path) => String(path)

// widgetVisibility.js fetches the user's groups with it; a public page has no
// signed-in user, so the request is never made and a call fails safe.
const refuse = () => Promise.reject(new Error('Not available on a public page'))
export default {
	get: refuse,
	post: refuse,
	put: refuse,
	patch: refuse,
	delete: refuse,
}
