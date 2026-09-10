<?php

/**
 * Portaliq — Store-plane registrar
 *
 * Binds the name the store routes resolve to at OpenRegister's engine, so
 * `/api/store/items` and `/api/store/items/{slug}/install` dispatch.
 *
 * @category AppInfo
 * @package  OCA\Portaliq\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\AppInfo;

use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Server;
use Throwable;

/**
 * The one AppHost binding Portaliq takes: the store controller alias.
 *
 * WHY THIS EXISTS
 * ---------------
 * `src/manifest.json` declares a `type: "store"` page and a `store` block, and
 * the shared store page calls `/apps/portaliq/api/store/items`. OpenRegister
 * hosts that plane (ADR-080, ADR-114 Decision 4): the `store#…` route names in
 * `appinfo/routes.php` resolve to `OCA\Portaliq\Controller\StoreController`, a
 * class this app deliberately does NOT ship, and OpenRegister's
 * `AppHost\Bootstrap::aliasStoreController()` binds that name to the engine's
 * GenericStoreController with `portaliq` injected as the calling app.
 *
 * Portaliq binds its other controllers by hand and does not call
 * `Bootstrap::register()` — that call would also re-bind SettingsService, the
 * repair steps and the admin settings under this app's names. OpenRegister's
 * Routes docblock describes exactly this situation and offers exactly this
 * call, public for that reason. Before it was made (WOO-559) the manifest page
 * was live, the routes were absent, and the SPA catch-all answered the JSON
 * call with HTML 200 — "The store registry did not answer."
 *
 * WHY THE PRELUDE COMES FIRST (ADR-040)
 * -------------------------------------
 * `Coordinator::registerApps()` walks the SORTED app list, registering each
 * app's autoloader and calling its `register()` one app at a time, so an app
 * that sorts before `openregister` reaches this code while `OCA\OpenRegister\`
 * is not yet autoloadable — on a healthy instance. `portaliq` sorts after
 * `openregister` today, but sort order moves with the app id and is not a
 * contract; the prelude is idempotent and cheap, so it stays. Enforced by
 * hydra gate-64 (apphost-autoload-prelude).
 *
 * WHY A CLASS AND NOT FIVE LINES IN register()
 * --------------------------------------------
 * `Application` cannot be constructed in a unit test without a server
 * container; this class can, against a mocked registration context, so the
 * binding is asserted rather than trusted. It also confines the three
 * unavoidable static calls to one method.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) `Server::get()` is the only way to a
 *   service from a composition root that has no container yet,
 *   `OC_App::registerAutoloading()` is the only way to pull another app's
 *   PSR-4 prefix into the running process (ADR-040 prescribes both verbatim),
 *   and `Bootstrap::aliasStoreController()` is the engine's designated static
 *   entry point for an app that binds its controllers by hand.
 *
 * @spec exclude the store plane is OpenRegister's apphost-store-plane spec; Portaliq only declares (ADR-114 D4)
 */
final class StorePlaneRegistrar {

	/**
	 * The namespace the router prepends to the `store#…` route names.
	 *
	 * The alias MUST be registered under this exact name: Nextcloud resolves the
	 * route `store#search` to `OCA\Portaliq\Controller\StoreController::search()`
	 * and asks the app container for that class by name.
	 */
	public const CONTROLLER_NAMESPACE = 'OCA\\Portaliq\\Controller';

	/**
	 * Alias `Controller\StoreController` at OpenRegister's GenericStoreController.
	 *
	 * Every failure of the prelude is swallowed on purpose: OpenRegister absent,
	 * disabled, or too old is a supported degraded state in which nothing is
	 * bound and the store routes report their missing controller at dispatch
	 * time instead of taking the whole app registration down with them. Three
	 * guards cover the three states, because none of them implies the others:
	 * `isInstalled()` (a disabled app still has a path and autoloadable
	 * classes), `class_exists()` (the app is there but predates AppHost), and
	 * `method_exists()` — `Bootstrap` shipped 2026-08-29, `aliasStoreController()`
	 * only on 2026-09-04, so every OpenRegister release up to v2.0.12 has the
	 * class WITHOUT the method and would otherwise raise an `Error` here that
	 * Nextcloud logs at emergency level on every request (review of #500).
	 *
	 * @param IRegistrationContext $context    The app's registration context.
	 * @param IAppManager|null     $appManager Injected for tests; resolved from the
	 *                                         server container when omitted.
	 *
	 * @return bool True when the alias was registered, false when OpenRegister's
	 *              AppHost is not available to this process.
	 *
	 * The contract lives in OpenRegister's openspec/specs/apphost-store-plane/
	 * spec.md ("a leaf app MUST declare its store rather than implement one");
	 * Portaliq declares it in src/manifest.json and takes the engine's binding
	 * here, so there is no Portaliq requirement of its own to anchor.
	 *
	 * @spec exclude the store plane is OpenRegister's apphost-store-plane spec; Portaliq only declares (ADR-114 D4)
	 */
	public function register(IRegistrationContext $context, ?IAppManager $appManager = null): bool {
		try {
			$manager = ($appManager ?? Server::get(IAppManager::class));
			if ($manager->isInstalled('openregister') === false) {
				// Present-but-disabled still resolves a path; only this answers "off".
				return false;
			}

			$orPath = $manager->getAppPath('openregister');
			\OC_App::registerAutoloading('openregister', $orPath);
		} catch (Throwable) {
			// OpenRegister absent — fall through to the degraded path.
		}

		if (class_exists('OCA\\OpenRegister\\AppHost\\Bootstrap') === false
			|| method_exists('OCA\\OpenRegister\\AppHost\\Bootstrap', 'aliasStoreController') === false
		) {
			return false;
		}

		\OCA\OpenRegister\AppHost\Bootstrap::aliasStoreController(
			context: $context,
			appId: Application::APP_ID,
			controllerNs: self::CONTROLLER_NAMESPACE
		);

		return true;
	}//end register()
}//end class
