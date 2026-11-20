<?php

/**
 * Portaliq Unowned Save Options
 *
 * Builds the OpenRegister save options of an anonymous create: the target, and
 * an actor that stamps the system identity as owner where the installed
 * OpenRegister offers that (REQ-PTB-012).
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
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-contributed-action-must-post-where-its-type-is-honoured-req-ptb-012
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\SaveActor;
use ReflectionClass;

/**
 * The save options of an anonymous create.
 *
 * GUARDED ON THE INSTALLED OPENREGISTER. An OpenRegister without
 * `SaveActor::$unowned` would fail the save on an unknown named argument, so
 * the actor is only passed when its constructor takes `unowned`; an older
 * install writes the row as it always did.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-contributed-action-must-post-where-its-type-is-honoured-req-ptb-012
 */
final class UnownedSaveOptions {

	/**
	 * The save options for an anonymous create in a register and schema.
	 *
	 * @param string $register The register slug/id.
	 * @param string $schema   The schema slug.
	 *
	 * @return ObjectSaveOptions The options.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-contributed-action-must-post-where-its-type-is-honoured-req-ptb-012
	 */
	public static function forTarget(string $register, string $schema): ObjectSaveOptions {
		$target = new ObjectFindTarget(register: $register, schema: $schema);
		if (self::openRegisterStampsUnowned() === false) {
			return new ObjectSaveOptions(target: $target);
		}

		return new ObjectSaveOptions(target: $target, actor: new SaveActor(unowned: true));
	}//end forTarget()

	/**
	 * Whether the installed OpenRegister takes `unowned` on its save actor
	 * and `actor` on its save options.
	 *
	 * @return bool
	 */
	private static function openRegisterStampsUnowned(): bool {
		if (class_exists(SaveActor::class) === false) {
			return false;
		}

		return self::constructorTakes(class: SaveActor::class, parameter: 'unowned')
			&& self::constructorTakes(class: ObjectSaveOptions::class, parameter: 'actor');
	}//end openRegisterStampsUnowned()

	/**
	 * Whether a class's constructor declares a parameter of that name.
	 *
	 * @param class-string $class     The class.
	 * @param string       $parameter The parameter name.
	 *
	 * @return bool
	 */
	private static function constructorTakes(string $class, string $parameter): bool {
		$constructor = (new ReflectionClass($class))->getConstructor();
		if ($constructor === null) {
			return false;
		}

		foreach ($constructor->getParameters() as $declared) {
			if ($declared->getName() === $parameter) {
				return true;
			}
		}

		return false;
	}//end constructorTakes()
}//end class
