<?php

/**
 * Portaliq Application
 *
 * Main application class for the Portaliq Nextcloud app.
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
 *
 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
 *   (file-level @spec tag — link back to the REQUIREMENT this file exists to
 *   satisfy. Multiple @spec tags allowed. Public methods SHOULD also carry
 *   their own @spec tag. ADR-003.
 *
 *   register() wires PortalAuthMiddleware, the fail-closed bearer guard that
 *   enforces that requirement's anonymous/elevated split.
 *
 *   Point at the canonical spec under `openspec/specs/`, never at
 *   `openspec/changes/<name>/` — a change directory is temporary, and every
 *   tag into it dangles once the change is archived or dropped. This tag was
 *   inherited from nextcloud-app-template and read `#task-N`, a literal
 *   placeholder that resolved to nothing. See ConductionNL/.github#228.)
 */

declare(strict_types=1);

namespace OCA\Portaliq\AppInfo;

use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Middleware\ApiContractMiddleware;
use OCA\Portaliq\Middleware\PortalAuthMiddleware;
use OCA\Portaliq\Middleware\PublicApiCorsMiddleware;
use OCA\Portaliq\Notification\Notifier;
use OCA\Portaliq\Service\HostRootRedirect;
use OCA\Portaliq\Service\ServiceRegistrar;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IRequest;
use OCA\Portaliq\Listener\ListenerRegistrar;
use OCA\Portaliq\Contribution\ContributionContractRegistrar;

/**
 * Main application class for the Portaliq Nextcloud app.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A bootstrap names every
 *  service and listener the app registers, so its coupling counts the app's
 *  wiring rather than this class's own complexity. Two independent changes met
 *  here and each added its own: the traffic analytics resolver alias, and the
 *  landing-page event listener pair. Splitting the bootstrap to lower the
 *  number would move the same names one file along.
 *
 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
 */
class Application extends App implements IBootstrap {
	public const APP_ID = AppIdentity::APP_ID;

	/**
	 * Constructor for the Application class.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct(appName: self::APP_ID);
	}//end __construct()

	/**
	 * Register event listeners and services.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 */
	public function register(IRegistrationContext $context): void {
		// The API contract (version header, deprecation of unversioned paths,
		// problem details). Registered first: Nextcloud runs afterController in
		// reverse order, so it sees the final response of every other middleware.
		$context->registerMiddleware(class: ApiContractMiddleware::class);
		// The app's own composer dependencies (portal-traffic-visitors-and-geo
		// added the first runtime one: maxmind-db/reader). Nextcloud loads
		// an app's classes under OCA\ by convention and nothing else; the
		// same include every fleet app with a vendored dependency carries.
		// Measured before this line existed: the resolver logged
		// 'Class "MaxMind\Db\Reader" not found' on the throwaway instance
		// while the very same file opened fine under the test bootstrap,
		// which loads the autoloader itself.
		include_once __DIR__ . '/../../vendor/autoload.php';

		// Initialize register and schemas on install/upgrade — registered via
		// appinfo/info.xml <repair-steps> (pre- and post-migration). The
		// programmatic IRegistrationContext::registerRepairStep() was removed in
		// Nextcloud 34, so calling it here fatals app registration (and, thrown
		// during the Coordinator pass, blanks the whole Settings framework and
		// blocks the app's own version-upgrade from recording). info.xml is the
		// NC34-supported mechanism and already declares this step.
		// AI Chat Companion (hydra ADR-034/035): this app no longer registers a
		// hand-written IMcpToolProvider. Its tools are derived from the
		// `x-openregister-mcp` dialect declared on the schemas in
		// lib/Settings/portaliq_register.json, so ExampleToolProvider.php was
		// deleted rather than filled in.

		// Fail-closed bearer guard for PortalProtected controllers (e.g.
		// ContributionController). Public auth-edge routes are untouched.
		$context->registerMiddleware(PortalAuthMiddleware::class);

		// Reflect-Origin CORS on the public content and traffic responses, so
		// a statically built site on its own domain can read content and post
		// a traffic batch without a preflight (portal-traffic-analytics).
		$context->registerMiddleware(PublicApiCorsMiddleware::class);

		// Portal contributions are discovered by convention FQCN
		// (OCA\{Namespace}\Portal\PortalContributionProvider) — see
		// PortalContributionRegistry — so no per-provider registration is needed
		// here; the DI container constructs each app's provider by reflection.

		(new ListenerRegistrar())->register(context: $context);

		(new ServiceRegistrar())->register(context: $context);

		// Traffic reports and alerts (portal-traffic-reporting) reach a
		// user as an in-app notification beside the mail; this renders it.
		$context->registerNotifierService(Notifier::class);

		// The store plane (ADR-080, ADR-114 Decision 4). The manifest's
		// `type: "store"` page calls /api/store/items; appinfo/routes.php declares
		// the engine's two store routes, and they resolve to
		// Controller\StoreController — a class this app does NOT ship.
		// StorePlaneRegistrar binds that name to OpenRegister's
		// GenericStoreController: the one AppHost binding this app takes, since
		// it wires everything else by hand (WOO-559). Without it the SPA
		// catch-all answered the store page's JSON call with HTML 200.
		(new StorePlaneRegistrar())->register($context);

		// OpenRegister asks which published forms write into a schema that
		// is about to change (decision 179). Portaliq answers for its form
		// bindings.
		(new FormDependentsRegistrar())->register($context);
		(new ContributionContractRegistrar())->register(context: $context);

	}//end register()

	/**
	 * Boot the application.
	 *
	 * Core boots every app before it matches the route, and answers the bare
	 * address of the instance (`/`) itself when no route matches. So the bare
	 * address of a portal's verified domain is sent to the site here, and
	 * every other request is left to core (portal-host-root-redirect).
	 *
	 * @param IBootContext $context The boot context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
	 * @spec openspec/changes/portal-host-root-redirect/specs/portal-settings/spec.md#requirement-the-bare-address-of-a-portal-host-must-lead-to-its-site-req-hrr-001
	 */
	public function boot(IBootContext $context): void {
		$container = $context->getAppContainer();
		$container->get(HostRootRedirect::class)->handle(request: $container->get(IRequest::class));
	}//end boot()
}//end class
