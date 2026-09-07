<?php

/**
 * Portaliq Portal Provider Locator
 *
 * Answers one question: given an app id, which contribution provider object
 * does that app ship, if any? Split out of PortalContributionRegistry so the
 * registry is left doing only what its name says — aggregating and filtering
 * contributions — and so the FQCN derivation, which is the part that has
 * silently gone wrong, has a name and a test of its own.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Locates one app's portal contribution provider by convention FQCN.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 */
class PortalProviderLocator {
	/**
	 * FQCN template each contributing app implements its provider at. `%s` is
	 * the app's namespace (see `namespaceFor()`). Discovering by concrete class
	 * — rather than an alias — is what makes cross-app discovery work: the DI
	 * container constructs any autoloadable class by reflection, whereas a
	 * registerServiceAlias only resolves inside the registering app's container.
	 */
	public const PROVIDER_CLASS = 'OCA\\%s\\Portal\\PortalContributionProvider';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager For reading each app's declared namespace.
	 * @param ContainerInterface $container  For constructing the provider.
	 * @param LoggerInterface    $logger     The logger.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve one app's contribution provider by convention FQCN, or null.
	 *
	 * An app contributes by shipping `OCA\{Namespace}\Portal\PortalContributionProvider`
	 * with `getAudience()` + `getContribution()` — no need to implement (and thus
	 * depend on) Portaliq's interface.
	 *
	 * @param string $appId The app id.
	 *
	 * @return object|null The provider instance, or null when the app ships none.
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T04
	 */
	public function locate(string $appId): ?object {
		$candidate = sprintf(self::PROVIDER_CLASS, $this->namespaceFor(appId: $appId));
		if (class_exists($candidate) === false) {
			return null;
		}

		try {
			$instance = $this->container->get($candidate);
		} catch (Throwable $e) {
			$this->logger->debug('Portaliq: contribution provider not resolvable', ['app' => $appId, 'reason' => $e->getMessage()]);
			return null;
		}

		if (is_object($instance) === true) {
			return $instance;
		}

		return null;
	}//end locate()

	/**
	 * The PHP namespace an app declares, or the ucfirst guess when it declares none.
	 *
	 * The namespace comes from the app's own `appinfo/info.xml` `<namespace>`,
	 * which is the only authority for it: an app id is lowercase by definition,
	 * a namespace is not, and `ucfirst()` guesses right only for the ids that
	 * happen to be one lowercase word. `zaakafhandelapp` declares
	 * `ZaakAfhandelApp` and `petstore` declares `PetStore`, so the old ucfirst
	 * derivation asked the autoloader for classes that do not exist.
	 * `class_exists()` on an unloadable class is `false`, not an error, so both
	 * apps were skipped in silence — zaakafhandelapp's entire `citizen`
	 * contribution never reached the portal, while its own provider unit test
	 * stayed green because that test never goes through this derivation.
	 * Measured on the dev instance: the ucfirst FQCN resolved for 11 of 13
	 * installed providers and failed for exactly those two.
	 *
	 * `IAppManager::getAppInfo()` returns null for an app whose `info.xml`
	 * cannot be read, and a `<namespace>` element is optional; `?? ''` covers
	 * both, since subscripting null yields null there rather than warning.
	 * Either absence falls back to `ucfirst()` rather than to an empty
	 * namespace, which would resolve for nothing and hide the app instead.
	 *
	 * @param string $appId The app id.
	 *
	 * @return string The namespace segment to build the provider FQCN from.
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T04
	 */
	private function namespaceFor(string $appId): string {
		$namespace = trim((string)($this->appManager->getAppInfo($appId)['namespace'] ?? ''));

		if ($namespace === '') {
			return ucfirst($appId);
		}

		return $namespace;
	}//end namespaceFor()
}//end class
