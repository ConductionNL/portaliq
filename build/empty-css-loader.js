/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Drops a stylesheet from the bundle, returning nothing in its place.
 *
 * A four-line loader rather than a dependency: `null-loader` is exactly this
 * and adding a package to delete bytes is a poor trade. Used by
 * `webpack.site.js` for the component library's own dist CSS, which the public
 * site already receives as a linked stylesheet — see the rule there for the
 * measurement.
 *
 * @return {string} Nothing.
 */
module.exports = function emptyCssLoader() {
	return ''
}
