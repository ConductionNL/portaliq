<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\GuestActionRegistry;
use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * `guestAction()` answers only from a provider that serves the `guest`
 * audience, only for an action marked `guest`, and only for an installed app.
 * Built on the real PortalProviderLocator and PortalManifestNormaliser.
 *
 * @spec openspec/changes/archive/2026-10-01-identity-guest-page-for-signed-links/tasks.md#T01
 */
class GuestActionRegistryTest extends TestCase {

	private const PROVIDER_FQCN = 'OCA\\Portaliq\\Portal\\PortalContributionProvider';

	/**
	 * A resident action of the same id is never a guest's, and an unknown
	 * app, an unknown action and a plain action all answer null.
	 *
	 * @spec openspec/changes/archive/2026-10-01-identity-guest-page-for-signed-links/tasks.md#T01
	 */
	public function testGuestActionIsFoundOnlyForTheGuestAudience(): void {
		$guestProvider = new class {

			public function getAudiences(): array {
				return ['citizen', 'guest'];
			}

			public function getContribution(array $subject): array {
				if (($subject['audience'] ?? '') !== 'guest') {
					return ['actions' => [['id' => 'withdraw', 'endpoint' => '/apps/portaliq/api/resident-withdraw']]];
				}

				return [
					'actions' => [
						['id' => 'withdraw', 'guest' => true, 'endpoint' => '/apps/portaliq/api/withdraw', 'tokenField' => 'token'],
						['id' => 'plain', 'endpoint' => '/apps/portaliq/api/plain'],
					],
				];
			}
		};

		$registry = $this->registry(['portaliq'], $guestProvider);
		$found = $registry->guestAction('portaliq', 'withdraw');
		$this->assertNotNull($found);
		$this->assertSame('/apps/portaliq/api/withdraw', $found['endpoint']);
		$this->assertSame('token', $found['tokenField']);
		$this->assertNull($registry->guestAction('portaliq', 'plain'));
		$this->assertNull($registry->guestAction('portaliq', 'refund'));
		$this->assertNull($registry->guestAction('shillinq', 'withdraw'));

	}//end testGuestActionIsFoundOnlyForTheGuestAudience()

	/**
	 * A provider that does not name the guest audience is never asked.
	 *
	 * @spec openspec/changes/archive/2026-10-01-identity-guest-page-for-signed-links/tasks.md#T01
	 */
	public function testAResidentOnlyProviderHasNoGuestAction(): void {
		$residentOnly = new class {

			public function getAudience(): string {
				return 'citizen';
			}

			public function getContribution(array $subject): array {
				return ['actions' => [['id' => 'withdraw', 'guest' => true, 'endpoint' => '/apps/portaliq/api/withdraw', 'tokenField' => 'token']]];
			}
		};

		$this->assertNull($this->registry(['portaliq'], $residentOnly)->guestAction('portaliq', 'withdraw'));

	}//end testAResidentOnlyProviderHasNoGuestAction()

	/**
	 * A guest action that asks for more than `low` trust is dropped whole.
	 *
	 * @spec openspec/specs/portal-guest-actions/spec.md#requirement-a-signed-link-opens-a-page-for-its-one-act-without-an-account-req-gst-002
	 */
	public function testAGuestActionAboveLowTrustIsNotFound(): void {
		$provider = new class {

			public function getAudiences(): array {
				return ['guest'];
			}

			public function getContribution(array $subject): array {
				return ['actions' => [['id' => 'pay', 'guest' => true, 'minTrust' => 'substantial', 'endpoint' => '/apps/portaliq/api/pay', 'tokenField' => 'token']]];
			}
		};

		$this->assertNull($this->registry(['portaliq'], $provider)->guestAction('portaliq', 'pay'));

	}//end testAGuestActionAboveLowTrustIsNotFound()

	/**
	 * The registry on the real locator, with one provider behind the
	 * convention class name.
	 *
	 * @param array<int, string> $installed The installed apps.
	 * @param object             $provider  The provider.
	 *
	 * @return GuestActionRegistry
	 */
	private function registry(array $installed, object $provider): GuestActionRegistry {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn($installed);
		$apps->method('getAppInfo')->willReturnCallback(static fn (string $appId): array => ['id' => $appId]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($provider) {
				if ($id === self::PROVIDER_FQCN) {
					return $provider;
				}

				throw new RuntimeException('no service: ' . $id);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		return new GuestActionRegistry($apps, new PortalProviderLocator($apps, $container, $logger), $logger);

	}//end registry()
}//end class
