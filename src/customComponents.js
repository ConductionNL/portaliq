// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// V1 custom-component registry — kept for backward-compatibility reference.
//
// *** V2 WAY: use src/registry.js instead. ***
//
// This file is the v1 "page-only" registry consumed by CnAppRoot's
// `customComponents` prop. It works for v1 manifests and during the
// v1 → v2 transition period. Once the app fully migrates to a v2 manifest
// and no longer needs the `customComponents` prop, this file can be removed
// and the import in main.js deleted.
//
// CnAppRoot will emit a console.warn once per mount when a v2 manifest is
// loaded alongside a non-empty `customComponents` prop. That is expected
// behaviour during the transition; it does not break anything.
//
// Every COMPONENT entry here has an equivalent `kind: "page"` entry in
// src/registry.js. `openPortalSite` is the exception and the reason this file
// cannot be retired: it is a handler FUNCTION, not a component. The v2
// registry's five kinds are widget | modal | page | form-field | cell-renderer,
// none of which is a handler, and the manifest action dispatcher resolves
// `handler` strings against THIS map only (never `cnRegistry`). Retiring this
// file needs a handler kind in the library first.
//
// Resolution order at runtime (v1 path):
//   1. Built-in page types          (CnIndexPage, CnDetailPage, …)
//   2. Built-in widget types        (version-info, register-mapping, …)
//   3. customComponents (this file) ← consumer-injected components
//
// See hydra ADR-036 for the v2 registry design.

import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import {
	showConfirmation,
	showError,
	showInfo,
	showSuccess,
	showWarning,
} from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import InviteDialog from './dialogs/InviteDialog.vue'
import IssueAccountDialog from './dialogs/IssueAccountDialog.vue'
import NewsItemDialog from './dialogs/NewsItemDialog.vue'
import RefuseAccessRequestDialog from './dialogs/RefuseAccessRequestDialog.vue'
import { createAccessRequestHandlers } from './lib/accessRequestActions.js'
import { createConnectionHandlers } from './lib/connectionRegistry.js'
import { createFormBindingPreview } from './lib/formBindingPreview.js'
import { refreshList } from './lib/listRefresh.js'
import { createNewsApi, createNewsHandlers, NEWS_LIST } from './lib/newsAuthoring.js'
import { createOpenPortalSite } from './lib/openPortalSite.js'
import {
	createStaffAccountActions,
	createStaffAccountHandlers,
} from './lib/staffAccountActions.js'

/**
 * The `Open portal` row action, wired to Nextcloud's URL generator, toast and
 * translator. The factory itself imports none of them so it stays loadable in
 * `tests/open-portal-site.spec.mjs` — see src/lib/openPortalSite.js.
 */
const openPortalSite = createOpenPortalSite({
	generateUrl,
	notify: showInfo,
	translate: (text) => t('portaliq', text),
})

/**
 * The Grant and Refuse row actions on the Access requests page (#797), wired
 * to the POST, the confirmation, the refusal dialog and the toasts. The
 * factory imports none of them so `tests/access-requests.spec.mjs` can run it
 * as a plain node script; see src/lib/accessRequestActions.js.
 */
const accessRequestHandlers = createAccessRequestHandlers({
	post: (url, body) => axios.post(url, body),
	generateUrl,
	confirmGrant: (row) =>
		showConfirmation({
			name: t('portaliq', 'Grant this request?'),
			text: t('portaliq', '{name} can then see the cases of {party}.', {
				name: row?.displayName || row?.subjectRef || '',
				party: row?.onBehalfOf || '',
			}),
			labelConfirm: t('portaliq', 'Grant'),
			labelReject: t('portaliq', 'Cancel'),
		}),
	askReason: () => spawnDialog(RefuseAccessRequestDialog),
	notify: showSuccess,
	notifyError: showError,
	translate: (text) => t('portaliq', text),
	// A row handler gets no handle on the list, so the page reloads to show
	// the answered request in its new state.
	reload: () => window.location.reload(),
})

/**
 * The `Check form` row action on the Request forms page
 * (portal-intake-form-as-an-object T03): says which form a binding opens
 * today, or that it opens none and why. See src/lib/formBindingPreview.js.
 */
const formBindingPreview = createFormBindingPreview({
	post: (url, body) => axios.post(url, body),
	generateUrl,
	notify: showSuccess,
	notifyWarning: showWarning,
	notifyError: showError,
	translate: (text, vars) => t('portaliq', text, vars),
})
/**
 * `Issue an account` and `Invite someone` on the Accounts and Invitations
 * pages, and `Withdraw invitation` on an Invitations row
 * (identity-staff-account-screens T04, T05). Each goes through
 * PortalAccountAdminController behind portal.provision. The dialogs submit
 * themselves so a refusal is shown where the clerk can correct it; see
 * src/lib/staffAccountActions.js.
 */
const staffAccountHandlers = createStaffAccountHandlers({
	actions: createStaffAccountActions({
		post: (url, body) => axios.post(url, body),
		generateUrl,
		translate: (text, vars) => t('portaliq', text, vars),
		formatDate: (iso) => {
			const date = new Date(iso)
			return Number.isNaN(date.getTime()) ? iso : date.toLocaleDateString()
		},
	}),
	openIssue: (submit) => spawnDialog(IssueAccountDialog, { submit }),
	openInvite: (submit) => spawnDialog(InviteDialog, { submit }),
	confirmWithdrawInvitation: (row) =>
		showConfirmation({
			name: t('portaliq', 'Withdraw this invitation?'),
			text: t(
				'portaliq',
				'The link sent to {email} admits nobody after this.',
				{
					email: row?.email || '',
				},
			),
			labelConfirm: t('portaliq', 'Withdraw invitation'),
			labelReject: t('portaliq', 'Cancel'),
		}),
	notify: showSuccess,
	notifyError: showError,
	// A header or row handler gets no handle on the list, so the page reloads
	// to show the new account or the withdrawn invitation.
	reload: () => window.location.reload(),
})
/**
 * `New news item`, `Change`, `Publish` and `Take back` on the News page
 * (staff-news-screen). Each goes through NewsController's staff routes, never
 * the object form; see src/lib/newsAuthoring.js.
 */
const newsHandlers = createNewsHandlers({
	api: createNewsApi({
		get: (url) => axios.get(url),
		post: (url, body) => axios.post(url, body),
		put: (url, body) => axios.put(url, body),
		generateUrl,
	}),
	openDialog: (props) => spawnDialog(NewsItemDialog, props),
	currentUser: () => getCurrentUser()?.uid || '',
	translate: (text, vars) => t('portaliq', text, vars),
	notify: showSuccess,
	notifyError: showError,
	// A handler gets no handle on the list, so the list is fetched again with
	// the page, sort and filters it had (news-list-keeps-its-page). Only a
	// list that was never fetched here falls back to reloading the page.
	reload: () => refreshList(NEWS_LIST) || window.location.reload(),
})

export default {
	// Header-action handler: the Integrations page's Add integration
	// (adopt-connection-registry). A FUNCTION, because it leaves the app for
	// integriq's Connections overview and a header action's `navigate` only
	// pushes a route inside this app. The action dispatcher resolves a handler
	// name against this map only.
	...createConnectionHandlers({
		generateUrl,
		assign: (url) => window.location.assign(url),
	}),

	/**
	 * `Open portal` row action on the Portals index page. A manifest action
	 * with `type: "handler"` resolves its `handler` string against this map
	 * and calls it with `{ actionId, item: row }` — see src/lib/
	 * openPortalSite.js for why the destination cannot be a static
	 * `navigate` target.
	 */
	openPortalSite,
	/**
	 * `Grant` and `Refuse` row actions on the Access requests index page
	 * (#797). Handlers, not object edits: an answer goes through
	 * AccessRequestAdminController, and a grant also records the mandate.
	 */
	...accessRequestHandlers,
	/**
	 * `Check form` row action on the Request forms index page.
	 */
	...formBindingPreview,
	/**
	 * `Issue an account`, `Invite someone` and `Withdraw invitation`
	 * (identity-staff-account-screens). Handlers, not the object form: the
	 * provision route de-duplicates the identity and the invite route mails
	 * the secret.
	 */
	...staffAccountHandlers,
	/**
	 * The News page's actions (staff-news-screen): handlers, not the object
	 * form, because news is written through NewsController's staff routes.
	 */
	...newsHandlers,
}
