<?php

/**
 * OpenRegister test stub: the public method signatures of openregister's
 * ConceptRepository on development (lib/Service/Vocabulary/ConceptRepository.php),
 * loaded only when OpenRegister is absent (tests/bootstrap-unit.php) and read
 * by psalm and phpstan. The bodies are not copied: a test doubles this class.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Stub
 * @package  OCA\OpenRegister\Service\Vocabulary
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude test stub of an OpenRegister class
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

use LogicException;

/**
 * Reads concept schemes and their concepts out of the vocabulary register.
 */
class ConceptRepository {

	/**
	 * The scheme object identified by its durable uri, or null.
	 *
	 * @param string $schemeUri The scheme's uri.
	 *
	 * @return array<string,mixed>|null The scheme's decoded object data.
	 */
	public function scheme(string $schemeUri): ?array {
		throw new LogicException('Stub: OpenRegister is not installed.');
	}//end scheme()

	/**
	 * Every concept of a scheme, keyed by its durable uri; the empty map when unreadable.
	 *
	 * @param string $schemeUri The scheme's uri.
	 *
	 * @return array<string,array<string,mixed>> The concepts, keyed by uri.
	 */
	public function conceptsOf(string $schemeUri): array {
		throw new LogicException('Stub: OpenRegister is not installed.');
	}//end conceptsOf()
}//end class
