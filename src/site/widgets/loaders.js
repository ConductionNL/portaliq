/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * THE LOADERS, AND NOTHING ELSE (site-nlds-widget-palette REQ-SNW-011).
 *
 * The site's renderer needs to know which keys it may mount and how to fetch
 * each one; that map IS the public gate (ADR-084 §5), so it belongs in the
 * site entry. Everything else about a widget does not: a label, a group, the
 * fields an author may edit and the words they may search for are the
 * editor's business, and shipping forty widgets' worth of that text to every
 * visitor of a public page is exactly the weight the budget in
 * `webpack.site.js` exists to refuse.
 *
 * So this file holds the imports and `index.js` holds the descriptions. The
 * entry then carries one small module of arrow functions, and
 * `tests/widget-registry.spec.mjs` asserts that no component, no meta and no
 * stylesheet of a widget reaches it.
 */

/**
 * The widget components, each loaded on demand, in its own chunk with its own
 * CSS package.
 *
 * @type {Record<string, () => Promise<object>>}
 */
export const loaders = {
	nlHeading: () => import('./nlHeading/NlHeading.vue'),
	nlParagraph: () => import('./nlParagraph/NlParagraph.vue'),
	nlLink: () => import('./nlLink/NlLink.vue'),
	nlLinkList: () => import('./nlLinkList/NlLinkList.vue'),
	nlList: () => import('./nlList/NlList.vue'),
	nlQuote: () => import('./nlQuote/NlQuote.vue'),
	nlButtonLink: () => import('./nlButtonLink/NlButtonLink.vue'),
	nlActionGroup: () => import('./nlActionGroup/NlActionGroup.vue'),
	nlDescriptionList: () => import('./nlDescriptionList/NlDescriptionList.vue'),
	nlImage: () => import('./nlImage/NlImage.vue'),
	nlTable: () => import('./nlTable/NlTable.vue'),
	nlSeparator: () => import('./nlSeparator/NlSeparator.vue'),
	nlCodeBlock: () => import('./nlCodeBlock/NlCodeBlock.vue'),
	nlAccordion: () => import('./nlAccordion/NlAccordion.vue'),
	nlVideo: () => import('./nlVideo/NlVideo.vue'),
	nlYouTube: () => import('./nlYouTube/NlYouTube.vue'),
	nlAlert: () => import('./nlAlert/NlAlert.vue'),
	nlNote: () => import('./nlNote/NlNote.vue'),
	nlBanner: () => import('./nlBanner/NlBanner.vue'),
	nlDialog: () => import('./nlDialog/NlDialog.vue'),
	nlDrawer: () => import('./nlDrawer/NlDrawer.vue'),
	nlProgressBar: () => import('./nlProgressBar/NlProgressBar.vue'),
	nlProgressCircle: () => import('./nlProgressCircle/NlProgressCircle.vue'),
	nlToggletip: () => import('./nlToggletip/NlToggletip.vue'),
	nlLanguageNav: () => import('./nlLanguageNav/NlLanguageNav.vue'),
	nlSignIn: () => import('./nlSignIn/NlSignIn.vue'),
	nlTaskNav: () => import('./nlTaskNav/NlTaskNav.vue'),
	nlTabs: () => import('./nlTabs/NlTabs.vue'),
	nlQuickTasks: () => import('./nlQuickTasks/NlQuickTasks.vue'),
	nlNewsList: () => import('./nlNewsList/NlNewsList.vue'),
	nlNewsArticle: () => import('./nlNewsArticle/NlNewsArticle.vue'),
	nlEventList: () => import('./nlEventList/NlEventList.vue'),
	nlCatalogue: () => import('./nlCatalogue/NlCatalogue.vue'),
}
