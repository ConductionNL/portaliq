<?php

/**
 * Thematiq test stub: ONLY the constant of thematiq's
 * lib/Service/TokenSetConverterService.php (development b938e4a) that
 * CustomTokenSetValidator reads. Loaded only when thematiq is absent
 * (tests/bootstrap.php). The converter itself is not copied: portaliq never
 * converts a set.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Thematiq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/thematiq
 */

declare(strict_types=1);

namespace OCA\Thematiq\Service;

/**
 * The component prefixes a converted set may declare.
 */
class TokenSetConverterService {

	/**
	 * Verbatim from thematiq.
	 *
	 * @var string[]
	 */
	public const COMPONENT_PREFIXES = ['--utrecht-', '--ams-', '--denhaag-', '--conduction-'];
}//end class
