// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The strings of slice f's site parts, in Dutch and English. Keys and texts
// are the React portal's own (src/portal/i18n/*.json): the key is the English
// source string. tests/install-banner.spec.mjs checks every key InstallBanner
// uses is here, in both languages, and matches the shared bundle.

export default {
	nl: {
		'Install this app': 'App installeren',
		'Install this app on your device?': 'Wilt u deze app op uw apparaat installeren?',
		Install: 'Installeren',
		'Not now': 'Niet nu',
	},
	en: {
		'Install this app': 'Install this app',
		'Install this app on your device?': 'Install this app on your device?',
		Install: 'Install',
		'Not now': 'Not now',
	},
}
