<?php

/**
 * Portaliq Mail Log (mail-templates-admin-screen)
 *
 * @category Mail
 * @package  OCA\Portaliq\Service\Mail
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
 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Mail;

/**
 * An address with its middle hidden.
 *
 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t03
 */
class RecipientMask {
	/**
	 * An address with its middle hidden: "sanne@example.nl" becomes "s***@example.nl".
	 *
	 * @param string $email The address.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t03
	 */
	public function mask(string $email): string {
		$email = trim($email);
		$atSign = strrpos($email, '@');
		if ($atSign === false || $atSign === 0) {
			return '***';
		}

		return substr($email, 0, 1) . '***' . substr($email, $atSign);
	}//end mask()
}//end class
