<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Repair;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Repair\InitializeSettings;
use OCA\Portaliq\Service\SettingsService;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * portal-auth-edge-session-hardening: the install/upgrade repair step
 * generates a dedicated `jwt_signing_secret` exactly once — a fresh install
 * is safe-by-default without an operator having to configure anything, and
 * re-running the step (upgrades) never overwrites an already-configured
 * secret, which would invalidate every live portal session.
 *
 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#1.2
 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#4.2
 */
class InitializeSettingsTest extends TestCase
{

    public function testGeneratesADedicatedSecretWhenUnset(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('');

        $stored = null;
        $config->expects($this->once())
            ->method('setAppValue')
            ->with(Application::APP_ID, 'jwt_signing_secret', $this->callback(function (string $secret) use (&$stored) {
                $stored = $secret;
                return strlen($secret) >= 32;
            }));

        $this->repair(config: $config)->run($this->repairOutput());

        $this->assertNotNull($stored);

    }//end testGeneratesADedicatedSecretWhenUnset()

    public function testIdempotentOnRerunWithAnAlreadyConfiguredSecret(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('already-configured-dedicated-secret-000');

        // Re-running the repair step (e.g. on upgrade) must NEVER overwrite an
        // existing secret — that would invalidate every live session.
        $config->expects($this->never())->method('setAppValue');

        $this->repair(config: $config)->run($this->repairOutput());

    }//end testIdempotentOnRerunWithAnAlreadyConfiguredSecret()

    public function testTooShortExistingSecretIsRegenerated(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('short');

        $config->expects($this->once())->method('setAppValue');

        $this->repair(config: $config)->run($this->repairOutput());

    }//end testTooShortExistingSecretIsRegenerated()

    private function repair(IConfig $config): InitializeSettings
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isOpenRegisterAvailable')->willReturn(false);

        $random = $this->createMock(ISecureRandom::class);
        $random->method('generate')->willReturn(str_repeat('a', 32));

        return new InitializeSettings(
            $settingsService,
            $this->createMock(LoggerInterface::class),
            $config,
            $random
        );

    }//end repair()

    private function repairOutput(): IOutput
    {
        return $this->createMock(IOutput::class);

    }//end repairOutput()

}//end class
