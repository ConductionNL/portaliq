<?php

/**
 * Portaliq GuestActionRegistry
 *
 * Finds one declared guest action (identity-guest-page-for-signed-links D1):
 * the act a person without an account may do from a link a contributing app
 * signed. Its own class beside PortalContributionRegistry, which aggregates
 * a signed-in subject's contributions; a guest is asked for one action only.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-guest-actions/spec.md#requirement-a-signed-link-opens-a-page-for-its-one-act-without-an-account-req-gst-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One guest action of one installed app, or nothing.
 *
 * @spec openspec/specs/portal-guest-actions/spec.md#requirement-a-signed-link-opens-a-page-for-its-one-act-without-an-account-req-gst-002
 */
class GuestActionRegistry {
	/**
	 * The audience a provider declares to serve guests.
	 */
	public const GUEST_AUDIENCE = 'guest';

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager For the installed apps.
	 * @param PortalProviderLocator $locator Finds each app's provider.
	 * @param LoggerInterface $logger The logger.
	 * @param PortalManifestNormaliser $normaliser The fail-closed contribution sanitiser.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly PortalProviderLocator $locator,
		private readonly LoggerInterface $logger,
		private readonly PortalManifestNormaliser $normaliser = new PortalManifestNormaliser(),
	) {
	}//end __construct()

	/**
	 * One declared guest action, or null. Only an installed app whose
	 * provider serves the `guest` audience is asked, as a `low`-trust guest,
	 * and only an action marked `guest` that survived normalisation (which
	 * drops any guest action above `low`) is returned. Null covers an unknown
	 * app, an unknown action and a resident-only action alike, so the caller
	 * answers them with the same 404.
	 *
	 * @param string $appId The contributing app.
	 * @param string $actionId The declared action id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-10-01-identity-guest-page-for-signed-links/tasks.md#T01
	 */
	public function guestAction(string $appId, string $actionId): ?array {
		$contribution = $this->guestContribution(appId: $appId);
		foreach (($contribution['actions'] ?? []) as $action) {
			if (($action['id'] ?? '') === $actionId && ($action['guest'] ?? false) === true) {
				return $action;
			}
		}

		return null;
	}//end guestAction()

	/**
	 * The normalised contribution an app gives a guest, or [] when the app is
	 * not installed, has no provider, does not serve guests, or fails.
	 *
	 * @param string $appId The contributing app.
	 *
	 * @return array<string, mixed>
	 */
	private function guestContribution(string $appId): array {
		if (in_array($appId, $this->appManager->getInstalledApps(), true) === false) {
			return [];
		}

		$provider = $this->locator->locate(appId: $appId);
		if ($provider === null || $this->servesGuests(provider: $provider) === false) {
			return [];
		}

		try {
			$contribution = $provider->getContribution(['audience' => self::GUEST_AUDIENCE, 'trust' => 'low']);
			if (is_array($contribution) === false) {
				return [];
			}

			$contribution['app'] = $appId;
			return $this->normaliser->normalise(contribution: $contribution);
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: guest contribution failed', ['app' => $appId, 'reason' => $e->getMessage()]);
			return [];
		}
	}//end guestContribution()

	/**
	 * Whether a provider gives contributions and names the guest audience.
	 *
	 * @param object $provider The app's provider.
	 *
	 * @return bool
	 */
	private function servesGuests(object $provider): bool {
		if (method_exists($provider, 'getContribution') === false) {
			return false;
		}

		$audiences = [];
		if (method_exists($provider, 'getAudiences') === true) {
			$audiences = $provider->getAudiences();
		} else if (method_exists($provider, 'getAudience') === true) {
			$audiences = [$provider->getAudience()];
		}

		return is_array($audiences) === true && in_array(self::GUEST_AUDIENCE, $audiences, true) === true;
	}//end servesGuests()
}//end class
