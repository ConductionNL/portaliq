<?php

/**
 * OpenRegister signature double: the two ObjectService methods the portal writer
 * calls, with their signatures copied from openregister's class on
 * `development` (3f804c2fda). A test doubles the real class when OpenRegister
 * is loaded and this one when it is not, so an `onlyMethods` double refuses a
 * method or argument the real class does not have. It lives in portaliq's own
 * test namespace so it never stands in for OpenRegister in another test. The
 * bodies are never run: the double replaces them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Portaliq\Tests\Doubles
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Doubles;

use LogicException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IUser;

/**
 * Signature stand-in for OpenRegister's ObjectService.
 */
class OpenRegisterObjectService {
	/**
	 * Find one object by id or uuid.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function find(
		int|string $id,
		?array $_extend = [],
		bool $files = false,
		Register|string|int|null $register = null,
		Schema|string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_render = true,
		bool $_audit = true,
	): ?OpenRegisterObjectEntity {
		throw new LogicException('Stub: replace with a test double.');
	}//end find()

	/**
	 * Create or update an object.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 */
	public function saveObject(
		array|OpenRegisterObjectEntity $object,
		?array $extend = [],
		Register|string|int|null $register = null,
		Schema|string|int|null $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $silent = false,
		bool $_validation = true,
		?array $uploadedFiles = null,
		?IUser $currentUser = null,
		bool $failIfExists = false,
		bool $_unowned = false,
		bool $_dedupOverride = false,
	): OpenRegisterObjectEntity {
		throw new LogicException('Stub: replace with a test double.');
	}//end saveObject()
}//end class
