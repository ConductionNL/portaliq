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

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Portaliq\Event\LandingPageRequestedEvent;
use OCA\Portaliq\Listener\CmsCacheInvalidationListener;
use OCA\Portaliq\Listener\LandingPageRequestedEventListener;
use OCA\Portaliq\Listener\LandingPageSubmissionDispatchListener;
use OCA\Portaliq\Middleware\PortalAuthMiddleware;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Main application class for the Portaliq Nextcloud app.
 *
 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'portaliq';

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

		// Portal contributions are discovered by convention FQCN
		// (OCA\{Namespace}\Portal\PortalContributionProvider) — see
		// PortalContributionRegistry — so no per-provider registration is needed
		// here; the DI container constructs each app's provider by reflection.

		// Drop cached public content when a CMS object is written (ADR-086 §9).
		// The read cache holds NEGATIVE results too, so without this a route
		// keeps 404ing for the rest of the TTL after its page is created — the
		// editor sees a broken site and is right.
		foreach ([ObjectCreatedEvent::class, ObjectUpdatedEvent::class, ObjectDeletedEvent::class] as $event) {
			$context->registerEventListener($event, CmsCacheInvalidationListener::class);
		}

		// Landing-page-provisioning (ADR-041, contribution-landing-page-action):
		// a same-instance cross-app command letting a contributing app ask
		// Portaliq to provision a draft landing page + form, and the fail-safe
		// relay of a visitor's submission back to that app.
		$context->registerEventListener(LandingPageRequestedEvent::class, LandingPageRequestedEventListener::class);
		$context->registerEventListener(ObjectCreatedEvent::class, LandingPageSubmissionDispatchListener::class);
	}//end register()

	/**
	 * Boot the application.
	 *
	 * @param IBootContext $context The boot context
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
	 */
	public function boot(IBootContext $context): void {
	}//end boot()
}//end class
