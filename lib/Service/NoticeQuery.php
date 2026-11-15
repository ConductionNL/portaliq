<?php

/**
 * Portaliq Notice Query
 *
 * Which notices are asked for: one portal, one surface, at one moment.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/specs/portal-notices/spec.md#requirement-a-notice-shows-on-every-page-during-its-window-req-omn-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateTimeImmutable;

/**
 * The portal, surface and moment PortalNoticeReader::select() keeps notices for.
 *
 * @spec openspec/specs/portal-notices/spec.md#requirement-a-notice-shows-on-every-page-during-its-window-req-omn-001
 */
final class NoticeQuery {
	/**
	 * Constructor.
	 *
	 * @param string            $portal  The portal slug.
	 * @param string            $surface `site` or `portal`.
	 * @param DateTimeImmutable $now     Now.
	 */
	public function __construct(
		public readonly string $portal,
		public readonly string $surface,
		public readonly DateTimeImmutable $now,
	) {
	}//end __construct()
}//end class
