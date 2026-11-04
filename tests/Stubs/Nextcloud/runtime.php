<?php

/**
 * The Nextcloud runtime doubles of a run without Nextcloud
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

// Loaded by both bootstraps after a Nextcloud boot was tried. Each double
// loads only when the real class is absent, so a run inside Nextcloud (CI's
// PHPUnit matrix) uses the real ones.
if (class_exists(\OC::class) === false) {
	require_once __DIR__ . '/OC.php';
	\OC::$server = new \OCA\Portaliq\Tests\Stubs\Nextcloud\UnitServer();
}

if (class_exists(\Doctrine\DBAL\ParameterType::class) === false) {
	require_once __DIR__ . '/../Doctrine/DBAL/ParameterType.php';
	require_once __DIR__ . '/../Doctrine/DBAL/ArrayParameterType.php';
	require_once __DIR__ . '/../Doctrine/DBAL/Types/Types.php';
}
