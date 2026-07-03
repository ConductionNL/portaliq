// SPDX-License-Identifier: EUPL-1.2
//
// Entry point for the PUBLIC Portaliq portal SPA (React + NL Design System).
// This bundle is built separately from the app's internal Vue admin UI
// (see webpack.portal.js) and is served by templates/portal.php via a
// #[PublicPage] response. It is NOT a Nextcloud/Vue surface.

import React from 'react'
import { createRoot } from 'react-dom/client'
import App from './App.jsx'

// White-label runtime config, injected server-side by PortalPageController (T08)
// as window.RUNTIME_CONFIG. Defaults keep local dev running without a backend.
const RUNTIME_CONFIG = window.RUNTIME_CONFIG || {
	organisationName: 'Portaliq',
	theme: 'utrecht',
	apiBase: '/index.php/apps/portaliq/portal/api',
	audience: 'supplier',
	idp: null,
}

const mount = document.getElementById('portaliq-portal')
if (mount) {
	createRoot(mount).render(<App config={RUNTIME_CONFIG} />)
}
