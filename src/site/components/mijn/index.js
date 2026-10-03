// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The mijn omgeving blocks a contribution page renders, by block type. Every
// loader is lazy: a block, its action rows and their Den Haag CSS download
// only when a page holds one, so the site's entry stays inside its budget
// (site-mijn-omgeving-components design D1).
//
// @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-mijn-omgeving-components-must-use-the-den-haag-css-on-our-own-markup-and-load-on-demand-req-smo-001

/**
 * The block components, by block type.
 *
 * @type {Record<string, () => Promise<object>>}
 */
export const blocks = {
	tasks: () => import('./TasksBlock.vue'),
	inbox: () => import('./InboxBlock.vue'),
	cases: () => import('./CasesBlock.vue'),
	steps: () => import('./StepsBlock.vue'),
	recordSwitcher: () => import('./RecordSwitcher.vue'),
}
