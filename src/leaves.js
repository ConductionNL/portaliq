/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The `portaliq-leaves` bundle: portaliq's OpenRegister leaves, and nothing else.
 *
 * OpenRegister's `LeafScriptListener` enqueues this on OTHER apps' pages, so a
 * case page in dossiq can show the change proposals waiting on that case. On
 * portaliq's own pages `main.js` registers the leaf already.
 *
 * KEEP IT THIN. Anything imported here lands on other apps' pages: the leaf
 * registration and nothing else. No router, no pinia, no manifest, no
 * component library.
 *
 * @spec openspec/specs/change-proposal-queue/spec.md
 */
import { loadTranslations } from '@nextcloud/l10n'
import { registerProposalQueueLeaf } from './integrations/registerProposalQueueLeaf.js'

// Register first, translate second: the host may look for the leaf in the same
// tick, and awaiting the catalogue would lose that race on a cold cache.
registerProposalQueueLeaf()

// A missing catalogue for the viewer's language is not worth a warning on
// someone else's page; the leaf renders in English.
loadTranslations('portaliq').catch(() => {})
