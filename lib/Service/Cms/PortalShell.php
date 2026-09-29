<?php

/**
 * The public projection of a portal's shell: header shape, footer and regions.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-header-must-be-a-block-whose-shape-the-portal-chooses-req-ptb-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * Chosen fields of the portal record, projected onto named keys.
 *
 * The portal record is not anonymously readable; the content API serves a
 * curated projection of it. This class decides the shell part of that
 * projection, so nothing the record holds reaches the public by accident.
 */
class PortalShell {

	/**
	 * The header shapes a portal may choose. The first is the default.
	 */
	public const HEADER_VARIANTS = ['double', 'single'];

	/**
	 * The header variant: the portal's choice when it is known, else `double`.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return string `double` or `single`.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-header-must-be-a-block-whose-shape-the-portal-chooses-req-ptb-004
	 */
	public function headerVariant(array $portal): string {
		$chosen = $portal['headerVariant'] ?? null;
		if (is_string($chosen) === true && in_array($chosen, self::HEADER_VARIANTS, true) === true) {
			return $chosen;
		}

		return self::HEADER_VARIANTS[0];
	}//end headerVariant()

	/**
	 * The public part of the portal's authentication block.
	 *
	 * The modes, always; the register destination and its label only when the
	 * portal declares them. Provider configuration never leaves the record.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return array<string, mixed> `{modes, register?, registerLabel?}`.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-header-must-be-a-block-whose-shape-the-portal-chooses-req-ptb-004
	 */
	public function authentication(array $portal): array {
		$auth   = (array)($portal['authentication'] ?? []);
		$public = ['modes' => array_values((array)($auth['modes'] ?? ['public']))];

		foreach (['register', 'registerLabel'] as $key) {
			$value = trim((string)($auth[$key] ?? ''));
			if ($value !== '') {
				$public[$key] = $value;
			}
		}

		return $public;
	}//end authentication()
}//end class
