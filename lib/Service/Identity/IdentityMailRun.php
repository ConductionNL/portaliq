<?php

/**
 * Portaliq Identity Mail Run
 *
 * One identity mail being sent: the template and its keys, the address, the
 * portal and its name, the link with the secret in its fragment, and who
 * wrote the invitation. Built and used inside PortalIdentityMailer only.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity
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
 * @spec openspec/specs/portal-ways-in/spec.md#requirement-every-way-in-sends-its-secret-by-mail-req-iwi-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

/**
 * The values every step of one identity mail shares.
 *
 * @spec openspec/specs/portal-ways-in/spec.md#requirement-every-way-in-sends-its-secret-by-mail-req-iwi-001
 */
final class IdentityMailRun {
	/**
	 * Constructor.
	 *
	 * @param string                    $template The template key.
	 * @param array<string, mixed>      $keys     The template's fragment and source keys.
	 * @param string                    $email    The recipient.
	 * @param array<string, mixed>|null $portal   The portal the mail speaks for.
	 * @param string                    $name     The portal's name.
	 * @param string                    $link     The link, the secret in its fragment.
	 * @param array<string, string>     $details  `inviter`, `message` and `address`, all optional.
	 */
	public function __construct(
		public readonly string $template,
		public readonly array $keys,
		public readonly string $email,
		public readonly ?array $portal,
		public readonly string $name,
		public readonly string $link,
		public readonly array $details,
	) {
	}//end __construct()

	/**
	 * The portal's slug, or ''.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portal-ways-in/spec.md#requirement-every-way-in-sends-its-secret-by-mail-req-iwi-001
	 */
	public function portalSlug(): string {
		return trim((string)($this->portal['slug'] ?? ''));
	}//end portalSlug()
}//end class
