<?php

/**
 * Contract of PortalNoticeReader
 *
 * The public surface of PortalNoticeReader that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of PortalNoticeReader.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalNoticeReaderInterface {

	/**
	 * The schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA = 'portalNotice';

	/**
	 * The notices active now for one portal on one surface, newest first.
	 *
	 * @param string $portal  The portal slug.
	 * @param string $surface `site` or `portal`.
	 *
	 * @return array<int, array{id: string, message: string, level: string, linkLabel: string, linkUrl: string, endsAt: string}> At most three.
	 *
	 * @spec openspec/specs/portal-notices/spec.md#requirement-a-notice-shows-on-every-page-during-its-window-req-omn-001
	 */
	public function active(string $portal, string $surface): array;
}//end interface
