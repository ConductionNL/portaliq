<?php

/**
 * OpenRegister test stub: the public signature of openregister's
 * `AppHost\Bootstrap::aliasStoreController()` at openregister #9078, loaded
 * only when OpenRegister is absent (tests/bootstrap-unit.php) and read by
 * psalm and phpstan.
 *
 * Only the one entry point Portaliq calls is carried. Its body does what the
 * engine's does as far as the app container can see: it registers the leaf's
 * `<controllerNamespace>\StoreController` name. A call in the old
 * `appId:` / `controllerNs:` shape fails here exactly as it does on the engine.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category AppHost
 * @package  OCA\OpenRegister\AppHost
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

namespace OCA\OpenRegister\AppHost;

use OCP\AppFramework\Bootstrap\IRegistrationContext;
use RuntimeException;

/**
 * The AppHost composition root, reduced to the store-controller alias.
 *
 * @spec exclude test stub of an OpenRegister class
 */
final class Bootstrap {

	/**
	 * Bind the leaf's `StoreController` name in its container.
	 *
	 * @param IRegistrationContext $context  Leaf registration context.
	 * @param LeafAdoption         $adoption Leaf app id and namespace.
	 *
	 * @return void
	 *
	 * @spec exclude test stub of an OpenRegister class
	 */
	public static function aliasStoreController(IRegistrationContext $context, LeafAdoption $adoption): void {
		$appId = $adoption->appId;
		$context->registerService(
			rtrim($adoption->namespace(option: 'controllerNamespace', suffix: 'Controller'), '\\') . '\\StoreController',
			static function () use ($appId): never {
				throw new RuntimeException('Test stub: the generic store controller of ' . $appId . ' is not built here.');
			}
		);
	}//end aliasStoreController()
}//end class
