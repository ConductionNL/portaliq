// SPDX-License-Identifier: EUPL-1.2
//
// Entry point for the PUBLIC Portaliq portal SPA (React + NL Design System).
// This bundle is built separately from the app's internal Vue admin UI
// (see webpack.portal.js) and is served by templates/portal.php via a
// #[PublicPage] response. It is NOT a Nextcloud/Vue surface.

import { loadState } from '@nextcloud/initial-state'
import React from 'react'
import { createRoot } from 'react-dom/client'
import App from './App.jsx'
import { startHeightReporting } from './embedHeight.js'
import { createTranslator } from './i18n/index.js'

// Shell-level NL Design System theme tokens (portal-spa-nl-design-system-styling).
// The Utrecht components inject their own per-component CSS at runtime; this
// supplies the surrounding shell tokens/layout. Fed through webpack.portal.js's
// (previously unused) css-loader rule.
import './theme.css'

// White-label runtime config (portal-white-label-runtime-config), resolved
// server-side by PortalPageController::index() from the `?org={slug}` query
// parameter and injected via IInitialStateService (see templates/portal.php)
// — the same CSP-safe mechanism the rest of the app uses, never a raw
// `window.RUNTIME_CONFIG` global. The fallback default below is DEV-ONLY,
// for running this bundle standalone without a Nextcloud backend; in every
// real deployment the initial state is always present.
const RUNTIME_CONFIG = loadState('portaliq', 'runtimeConfig', {
	organisationName: 'Portaliq',
	organisationSlug: '',
	theme: 'default',
	logo: null,
	// portal-oidc-broker-login: secret-free {provider, label} pairs the login
	// buttons are built from; empty here means "no broker configured".
	oidcProviders: [],
	featureFlags: {},
	allowedEmbedOrigins: [],
	apiBase: '/index.php/apps/portaliq/portal/api',
	audience: 'supplier',
	// Resolved server-side from Accept-Language (portal-spa-i18n-locale-support);
	// 'nl' is the current de-facto default when no config is present at all.
	locale: 'nl',
})

// Locale-bound translator (portal-spa-i18n-locale-support): every
// user-visible string in App.jsx goes through this instead of a hard-coded
// Dutch literal.
const t = createTranslator(RUNTIME_CONFIG.locale || 'nl')

const mount = document.getElementById('portaliq-portal')
if (mount) {
	createRoot(mount).render(<App config={RUNTIME_CONFIG} t={t} />)
}

// embedded-intake-form T05: the frame's half of the height negotiation.
//
// Attached whenever this bundle is serving the frame route, which
// templates/embed.php marks with #portaliq-embed. Started here rather than
// inside a component, because the height has to be reported even when the
// frame renders a refusal message instead of a form: a refusal that collapses
// to nothing on the host page tells the visitor even less than the refusal
// does.
//
// 🔴 THIS IS NOT WHAT MAKES THE FORM VISIBLE. The declared min-height in the
// pasted snippet is. If this never runs the frame still renders at the floor,
// which is the whole reason the floor is in the markup rather than negotiated.
const embedMount = document.getElementById('portaliq-embed')
if (embedMount) {
	startHeightReporting()
}
