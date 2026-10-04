<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Controller\SettingsController;
use OCA\Portaliq\Service\InternalBaseUrl;
use OCA\Portaliq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The internal address for calls to this instance: what it accepts, what it
 * refuses, and how the admin settings store and show it.
 *
 * @spec openspec/changes/instance-loopback-self-calls/specs/instance-loopback/spec.md#requirement-an-administrator-can-name-the-internal-address
 */
class InternalBaseUrlTest extends TestCase {
	/**
	 * The fake app config store.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The service over a fake app config.
	 *
	 * @param LoggerInterface|null $logger The logger, when a test asserts on it.
	 *
	 * @return InternalBaseUrl
	 */
	private function address(?LoggerInterface $logger = null): InternalBaseUrl {
		$config = $this->getMockBuilder(IAppConfig::class)->disableOriginalConstructor()->getMock();
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($app === 'portaliq' ? ($this->stored[$key] ?? $default) : $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;
				return true;
			}
		);

		return new InternalBaseUrl($config, $logger ?? $this->createMock(LoggerInterface::class));
	}//end address()

	/**
	 * What the setting accepts and refuses.
	 *
	 * @return void
	 */
	public function testTheSettingAcceptsOnlyAPlainHttpAddress(): void {
		$address = $this->address();

		$this->assertSame('', $address->normalise(value: '  '));
		$this->assertSame('http://nextcloud', $address->normalise(value: 'http://nextcloud/'));
		$this->assertSame('https://app.internal:8443/nextcloud', $address->normalise(value: 'HTTPS://app.internal:8443/nextcloud/'));
		$this->assertSame('http://10.0.0.5', $address->normalise(value: 'http://10.0.0.5'));

		foreach ([
			'ftp://nextcloud',
			'file:///etc/passwd',
			'nextcloud',
			'//nextcloud',
			'http://',
			'http:',
			'https:/nextcloud',
			'http://user:secret@nextcloud',
			'http://user@nextcloud',
			'http://nextcloud/?debug=1',
			'http://nextcloud/#x',
			'http://nextcloud/../etc',
			'http://nextcloud/a/./b',
			'http://nextcloud/%2e%2e/etc',
			'http://nextcloud/a:b',
			'http://next cloud',
			'http://nextcloud\\evil',
		] as $invalid) {
			$this->assertNull($address->normalise(value: $invalid), $invalid . ' must be refused.');
		}
	}//end testTheSettingAcceptsOnlyAPlainHttpAddress()

	/**
	 * A valid address is stored normalised, an invalid one is refused and the
	 * stored one stays, an empty one clears it.
	 *
	 * @return void
	 */
	public function testStoreValidatesFirst(): void {
		$address = $this->address();

		$this->assertTrue($address->store(value: 'http://nextcloud-app/'));
		$this->assertSame('http://nextcloud-app', $this->stored['internal_base_url']);

		$this->assertFalse($address->store(value: 'http://user:pw@evil/../x'));
		$this->assertSame('http://nextcloud-app', $this->stored['internal_base_url']);

		$this->assertTrue($address->store(value: ''));
		$this->assertSame('', $this->stored['internal_base_url']);
	}//end testStoreValidatesFirst()

	/**
	 * A stored value that is invalid (set with occ) is ignored, with one
	 * warning per request; a valid one comes back normalised.
	 *
	 * @return void
	 */
	public function testAnInvalidStoredValueIsIgnoredWithOneWarning(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('invalid internal_base_url'));
		$address = $this->address(logger: $logger);

		$this->stored['internal_base_url'] = 'http://nextcloud/../etc';
		$this->assertSame('', $address->configured());
		$this->assertSame('', $address->configured());
		$this->assertSame('http://nextcloud/../etc', $address->stored());

		$this->stored['internal_base_url'] = ' http://nextcloud/ ';
		$this->assertSame('http://nextcloud', $address->configured());
	}//end testAnInvalidStoredValueIsIgnoredWithOneWarning()

	/**
	 * The admin settings: an administrator sees the stored address, others
	 * do not; a save stores a valid address and says when one was refused.
	 *
	 * @return void
	 */
	public function testTheAdminSettingsStoreAndShowTheAddress(): void {
		$this->stored['internal_base_url'] = 'http://nextcloud-app';
		$address = $this->address();

		$admin = $this->createMock(SettingsService::class);
		$admin->method('getSettings')->willReturn(['isAdmin' => true]);
		$this->assertSame('http://nextcloud-app', (new SettingsController($this->createMock(IRequest::class), $admin, $address))->index()->getData()['internal_base_url']);

		$user = $this->createMock(SettingsService::class);
		$user->method('getSettings')->willReturn(['isAdmin' => false]);
		$this->assertArrayNotHasKey('internal_base_url', (new SettingsController($this->createMock(IRequest::class), $user, $address))->index()->getData());

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnOnConsecutiveCalls(['internal_base_url' => 'http://u:p@evil'], ['internal_base_url' => 'http://other/'], ['register' => 'r']);
		$admin->method('updateSettings')->willReturn([]);
		$controller = new SettingsController($request, $admin, $address);

		$refused = $controller->update()->getData()['config'];
		$this->assertTrue($refused['internal_base_url_refused']);
		$this->assertSame('http://nextcloud-app', $refused['internal_base_url']);

		$saved = $controller->update()->getData()['config'];
		$this->assertArrayNotHasKey('internal_base_url_refused', $saved);
		$this->assertSame('http://other', $saved['internal_base_url']);

		$untouched = $controller->update()->getData()['config'];
		$this->assertArrayNotHasKey('internal_base_url', $untouched);
		$this->assertSame('http://other', $this->stored['internal_base_url']);
	}//end testTheAdminSettingsStoreAndShowTheAddress()
}//end class
