<?php

/**
 * Portaliq Task Mail
 *
 * The parts of one privacy-minimal task mail: where it goes, the
 * organisation it names, the deep link, and the words in the portal's
 * language.
 *
 * @category BackgroundJob
 * @package  OCA\Portaliq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-delivery-worker-settles-every-ledger-row-idempotently-and-in-isolation
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use OCP\IL10N;

/**
 * What PortalTaskDeliveryJob puts in one task mail.
 *
 * @spec openspec/specs/portal-task-delivery/spec.md#requirement-the-mail-says-which-kind-of-delivery-it-is-req-trd-002
 */
final class TaskMail {
	/**
	 * Constructor.
	 *
	 * @param string                $email            The recipient.
	 * @param string                $organisationName The organisation the mail names.
	 * @param string                $deepLink         Where the resident acts.
	 * @param array<string, string> $keys             The subject and body translation keys of the kind.
	 * @param IL10N                 $l10n             The translations in the portal's language.
	 */
	public function __construct(
		public readonly string $email,
		public readonly string $organisationName,
		public readonly string $deepLink,
		public readonly array $keys,
		public readonly IL10N $l10n,
	) {
	}//end __construct()
}//end class
