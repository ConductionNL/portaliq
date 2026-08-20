<?php

/**
 * Portaliq Demo Portal Repair Step
 *
 * Provisions a themed, searchable portal on a fresh install so that the app
 * has something to show without an operator having to author it first.
 *
 * @category Repair
 * @package  OCA\Portaliq\Repair
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

namespace OCA\Portaliq\Repair;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalResolver;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Create a themed portal with a working search page on first install.
 *
 * WHY AN INSTALL STEP WRITES CONTENT AT ALL
 * -----------------------------------------
 * Installing openregister, portaliq and opencatalogi in that order leaves a
 * correct, fully configured stack that renders NOTHING: the portal renderer
 * resolves no portal, so every request 404s. The apps are installed and the
 * product is invisible, which is indistinguishable from a broken install to
 * everyone who has not read the CMS documentation first.
 *
 * THREE GUARDS, AND EACH ONE CLOSES A DIFFERENT FAILURE
 * -----------------------------------------------------
 * 1. It runs only when the instance has NO portals. An instance that is
 *    already in use has content whose absence is meaningful — an author may
 *    have deleted a portal deliberately — and adding one back on the next
 *    upgrade would be this step second-guessing them. "No portals at all" is
 *    the only state in which nothing can be overwritten and nothing can be
 *    contradicted.
 *
 * 2. `demo_portal=no` switches it off entirely, before the count is even
 *    taken, so an operator provisioning by other means is never surprised.
 *
 * 3. It NEVER FAILS THE INSTALL. Every failure path warns and returns. A
 *    repair step that throws aborts `occ app:install`, so a portal that could
 *    not be seeded would leave the app not installed at all — trading a
 *    cosmetic problem for a total one. This matters most on the machine least
 *    able to report it: an offline or air-gapped install, where OpenRegister
 *    may not have finished importing its register when this runs.
 *
 * WHAT IT DOES NOT DO: no network call, no directory registration, no sync.
 * Federation is configured by OpenCatalogi and exercised by cron. An install
 * hook that reached the network would hang a firewalled install on a
 * connect timeout, and the operator would see the installer stop with no
 * explanation.
 *
 * @spec openspec/changes/portal-federated-search/specs/portal-federated-search/spec.md#requirement-an-anonymous-visitor-must-be-able-to-search-federated-publications
 */
class InitializeDemoPortal implements IRepairStep {
	/**
	 * The app-config key that switches this step off.
	 */
	private const CONFIG_KEY = 'demo_portal';

	/**
	 * Set once this step has provisioned, so it can never provision twice.
	 *
	 * THE COUNT ALONE IS NOT A SUFFICIENT GUARD. `countObjects()` returns 0
	 * both when there are no portals and when the count FAILED — it catches
	 * Throwable and answers 0 either way. So "OpenRegister hiccupped" and
	 * "this instance is empty" are the same value, and the empty answer is the
	 * one that permits a write. This marker is what makes the guard mean what
	 * it says: a second run is refused on our own record of the first, not on
	 * a number that cannot distinguish failure from emptiness.
	 */
	private const MARKER_KEY = 'demo_portal_provisioned';

	/**
	 * The theme the seeded portal adopts.
	 *
	 * Resolved by PortalThemeResolver against nldesign's catalogue. When
	 * nldesign is absent the portal renders unthemed rather than wrong, which
	 * is that resolver's deliberate posture.
	 */
	private const THEME = 'rotterdam';

	/**
	 * The seeded portal's slug.
	 *
	 * A CONSTANT BECAUSE TWO OBJECTS HAVE TO AGREE ON IT. `CmsReader` scopes
	 * every content query by `portal` = the portal's SLUG — not its id — so a
	 * page whose `portal` field holds the UUID matches nothing, and the portal
	 * renders its own chrome around a 404. Nothing about that failure points at
	 * the reference: the portal resolves, the page object exists, is published,
	 * and is linked to a real portal id.
	 */
	private const SLUG = 'demo';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectWriter $writer Writes portal and page objects.
	 * @param IAppConfig $appConfig App configuration.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalObjectWriter $writer,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/portal-federated-search/specs/portal-federated-search/spec.md#requirement-an-anonymous-visitor-must-be-able-to-search-federated-publications
	 */
	public function getName(): string {
		return 'Provision a themed portal with search on first install';
	}//end getName()

	/**
	 * Seed the portal, unless anything at all suggests it should not.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-federated-search/specs/portal-federated-search/spec.md#requirement-an-anonymous-visitor-must-be-able-to-search-federated-publications
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, 'yes') === 'no') {
			$output->info('Demo portal disabled by configuration — nothing provisioned.');
			return;
		}

		if ($this->appConfig->getValueString(Application::APP_ID, self::MARKER_KEY, '') !== '') {
			$output->info('Demo portal was already provisioned once — not provisioning again.');
			return;
		}

		$existing = $this->writer->countObjects(
			register: PortalResolver::REGISTER,
			schema: 'portal'
		);

		if ($existing > 0) {
			$output->info(
				sprintf('%d portal(s) already present — leaving content untouched.', $existing)
			);
			return;
		}

		$portal = $this->writer->createAnonymousObject(
			register: PortalResolver::REGISTER,
			schema: 'portal',
			data: [
				'title' => 'Open Catalogi',
				'tagline' => 'Zoek in publicaties uit alle aangesloten catalogi',
				'slug' => self::SLUG,
				'status' => 'published',
				'theme' => self::THEME,
				// NO DOMAINS, AND THE PORTAL THEREFORE DOES NOT SERVE YET.
				//
				// PortalResolver has exactly two modes — explicit slug, or a
				// VERIFIED hostname — and deliberately no fallback, because a
				// "default portal" is how a multi-tenant host serves one
				// tenant's content under another tenant's domain. So this
				// portal answers only once an operator binds a hostname to it.
				//
				// Seeding a verified domain here would be this install hook
				// asserting control of a hostname on the operator's behalf,
				// which is precisely what the `verified` flag exists to stop.
				// A demo rig binds `localhost` itself, as a deployment
				// decision on a throwaway box; an app-store install must not
				// make that decision for somebody's server.
				//
				// Until then the portal is reachable by slug: /site?portal=demo
				'domains' => [],
				'locales' => ['nl'],
				// PUBLIC ONLY. Declaring a sign-in mode here would render a
				// login button with no identity provider behind it — an inert
				// control is a support ticket from every visitor who presses
				// it.
				'authentication' => ['modes' => ['public']],
			]
		);

		if ($portal === null) {
			$output->warning(
				'Could not create the demo portal — OpenRegister may not be ready yet. '
				. 'Install continues; re-run `occ maintenance:repair` once it is.'
			);
			$this->logger->warning('[portaliq] demo portal not provisioned: portal write returned null');
			return;
		}

		$portalId = ($portal['@self']['id'] ?? ($portal['id'] ?? null));
		if ($portalId === null) {
			$output->warning('Demo portal created but carries no id — search page not linked.');
			return;
		}

		$page = $this->writer->createAnonymousObject(
			register: PortalResolver::REGISTER,
			schema: 'page',
			data: [
				'title' => 'Zoeken',
				'route' => '/',
				'status' => 'published',
				'locale' => 'nl',
				'summary' => 'Doorzoek publicaties uit alle aangesloten catalogi.',
				// THE SLUG, NOT THE ID — see self::SLUG. CmsReader filters
				// content by slug, so the id here silently yields no pages.
				'portal' => self::SLUG,
				'body' => [
					'type' => 'grid',
					'widgets' => [
						[
							'id' => 'demo-federated-search',
							'widgetKey' => 'federatedSearch',
							'gridX' => 0,
							'gridY' => 0,
							'gridWidth' => 12,
							'gridHeight' => 8,
							// No `endpoint` prop: the block's default is
							// relative, so it finds OpenCatalogi on whichever
							// instance serves the portal. Writing an absolute
							// URL here would bake this machine's hostname into
							// seeded content and break the moment the instance
							// is reached by any other name.
							//
							// No `title` either — the page is already called
							// "Zoeken" and the renderer prints that above the
							// block. Passing one here rendered two headings
							// saying the same thing.
							//
							// `props` MUST BE A NON-EMPTY OBJECT. Both of the
							// obvious ways to say "no props" are rejected by
							// the schema, with two different messages:
							//
							//   []   → "expects object but got empty ({}) ...
							//           set this to null to clear the field"
							//   null → "should be type 'object' but is 'null'"
							//
							// The first message recommends exactly what the
							// second refuses. Either way the whole page write
							// fails, so the portal provisions and its only page
							// does not — a portal that renders its own chrome
							// around a 404.
							//
							// So it carries a real prop instead of an empty
							// one. A placeholder that names something actually
							// in the seeded corpus is more use to a first-time
							// visitor than the generic "Zoekterm" default.
							'props' => [
								'placeholder' => 'Bijvoorbeeld: subsidies',
							],
						],
					],
				],
			]
		);

		if ($page === null) {
			$output->warning('Demo portal created, but its search page was not — the portal will render empty.');
			$this->logger->warning('[portaliq] demo portal page not provisioned: page write returned null');
			return;
		}

		// Stamped only after BOTH writes landed. Setting it earlier would
		// record success for a half-provisioned instance and then refuse the
		// re-run that would have completed it.
		$this->appConfig->setValueString(Application::APP_ID, self::MARKER_KEY, (string)$portalId);

		$output->info('Provisioned the "Open Catalogi" portal with a federated search page at /.');
	}//end run()
}//end class
