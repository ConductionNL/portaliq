// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Webpack entry-point for the Nextcloud admin app-settings panel
// (Admin > Administration settings > App Template). This is DISTINCT
// from the manifest's `type: "settings"` page, which lives inside
// the SPA at `/settings` and is rendered by CnSettingsPage.
//
// Nextcloud's admin app-settings is a tiny standalone Vue mount into
// `#portaliq-settings` (see `templates/settings/admin.php`). Most
// new apps drive the entire settings story from the manifest's
// CnSettingsPage with `version-info` / `register-mapping` widgets and
// can simplify or remove this entry-point. It stays in the template
// because the Nextcloud admin section is the canonical place for
// "before the app boots" config (e.g. an app's OR register binding).

import { createApp } from 'vue'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import pinia from './pinia.js'
import AdminRoot from './views/AdminRoot.vue'

loadTranslations('portaliq', () => {
	const app = createApp(AdminRoot)
	// Vue 3: global API is per-app. PiniaVuePlugin was the Vue-2-only shim;
	// in Vue 3 the pinia instance IS the plugin.
	app.mixin({ methods: { t, n } })
	app.use(pinia)
	app.mount('#portaliq-settings')
})
