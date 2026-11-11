<?php

/**
 * Portaliq Sample Mail
 *
 * One sample mail an admin sends to try a mail template: the kind, the
 * subject and text to try, and the admin's own address.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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
 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t04
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

/**
 * What one MailTemplateAdminController::test() call sends.
 *
 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t04
 */
final class SampleMail {
	/**
	 * Constructor.
	 *
	 * @param string $templateKey The kind of mail, known to the renderer.
	 * @param string $subject     The subject line to try, or '' for the default.
	 * @param string $body        The text to try, or '' for the default.
	 * @param string $email       The admin's own, valid address.
	 */
	public function __construct(
		public readonly string $templateKey,
		public readonly string $subject,
		public readonly string $body,
		public readonly string $email,
	) {
	}//end __construct()
}//end class
