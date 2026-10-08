/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boot for the embed frame (site-reaches-portal-parity REQ-SRP-047): a portal
 * intake form framed on somebody else's website.
 *
 * ITS OWN SMALL ENTRY (`portaliq-embed`, built by webpack.site.js). It pulls in
 * neither the site nor the React portal: a municipality's page that frames
 * one form should not download a whole portal to show it.
 *
 * Like the site it touches no Nextcloud global: templates/embed.php owns the
 * whole document and hands the boot data over in a JSON block.
 */

import { createApp } from 'vue'
import EmbedFrame from './EmbedFrame.vue'
import { startHeightReporting } from '../shared/embedHeight.js'
import { MOUNT_ID, readEmbedConfig } from './frame.js'
import { createEmbedTranslator } from './strings.js'

import '@utrecht/paragraph-css/dist/index.css'
import '@utrecht/skip-link-css/dist/index.css'
import '@utrecht/link-css/dist/index.css'
import '@utrecht/alert-css/dist/index.css'
import '@utrecht/button-css/dist/index.css'
import '@utrecht/form-field-css/dist/index.css'
import '@utrecht/form-label-css/dist/index.css'
import '@utrecht/form-field-error-message-css/dist/index.css'
import '@utrecht/textbox-css/dist/index.css'

const element = document.getElementById(MOUNT_ID)

if (element) {
	const config = readEmbedConfig(document)
	createApp(EmbedFrame, {
		payload: config.payload,
		submitUrl: config.submitUrl,
		t: createEmbedTranslator(config.locale),
	}).mount(element)

	// The frame's half of the height negotiation. Started after the mount, so
	// the first report measures a rendered form, and started for a refusal
	// too: a refusal that collapses to nothing on the host page tells the
	// visitor even less than the refusal does. The declared min-height in the
	// pasted snippet is still what makes the frame visible when this never runs.
	startHeightReporting()
} else {
	// eslint-disable-next-line no-console
	console.error(`[portaliq-embed] no #${MOUNT_ID} element to mount into`)
}
