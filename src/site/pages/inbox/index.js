/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The signed-in pages of slice d (site-reaches-portal-parity): the inbox,
 * "My tasks", the messages with school and the school news. Keys are the
 * sections of the React portal's navigation (`special` in src/portal/App.jsx),
 * which is also how the site's page registry looks a section up. Every loader
 * is lazy, so a page downloads only when a resident opens it and the site's
 * entry bundle stays inside its budget.
 *
 * The timed task screens are not a section: a contribution page renders them
 * for a `timedTask` collection block, through `components.timedTask`.
 *
 * @typedef {object} SitePageProps
 * @property {object|null} session The session as `/portal/api/session` returns it (`subjectRef`, `audience`, `organisation`).
 * @property {object|null} portal The portal record.
 * @property {object} api The shared portal API bound to the site's bearer (src/portal/lib/portalApi.js `createPortalApi`).
 * @property {(key: string, vars?: object) => string} t The site translator; a key it does not know falls back to strings.js.
 * @property {(key: string, params?: object) => void} [navigate] Opens another page by key; without it the page emits `navigate` with an in-site route.
 * @property {string} [locale] The page language, `nl` or `en`.
 * @property {object} [entry] The navigation entry on screen.
 * @property {object} [contributions] The contributions aggregate (`unreadCount`).
 * @property {Array<object>} [nav] Every navigation entry (the inbox needs it to open a record).
 *
 * Events: `navigate` (an in-site route), `unread` (the inbox's new unread
 * count), `refresh` (read the contributions again, after a task is done).
 */

export { default as strings } from './strings.js'

/**
 * The pages, by section key.
 *
 * @type {Record<string, () => Promise<object>>}
 */
export const pages = {
	inbox: () => import('./InboxPage.vue'),
	tasks: () => import('./TasksPage.vue'),
	messages: () => import('./MessagesPage.vue'),
	news: () => import('./NewsPage.vue'),
}

/**
 * Parts another slice's pages render.
 *
 * @type {Record<string, () => Promise<object>>}
 */
export const components = {
	timedTask: () => import('../../components/timed-task/TimedTaskView.vue'),
}
