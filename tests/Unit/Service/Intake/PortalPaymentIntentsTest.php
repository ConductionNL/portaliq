<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\PortalPaymentIntents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Reading the payment status of an intent.
 */
#[CoversClass(PortalPaymentIntents::class)]
class PortalPaymentIntentsTest extends TestCase {
	/**
	 * Every stored intent shape reads exactly as recorded.
	 *
	 * @return void
	 */
	public function testEveryIntentIsAsRecorded(): void {
		$entity = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['object' => ['paymentStatus' => 'paid']];
			}
		};
		$cases  = [
			'entity' => [$entity, 'paid'],
			'plain' => [['paymentStatus' => 'open'], 'open'],
			'wrapped' => [['object' => ['paymentStatus' => 'failed'], 'paymentStatus' => 'outer'], 'failed'],
			'null wrapper' => [['object' => null, 'paymentStatus' => 'outer'], 'outer'],
			'scalar wrapper' => [['object' => 'x', 'paymentStatus' => 'outer'], null],
			'empty' => [['paymentStatus' => ''], null],
			'not a string' => [['paymentStatus' => 1], null],
			'none' => [[], null],
			'no object' => [null, null],
			'an object' => [new \stdClass(), null],
		];
		foreach ($cases as $name => [$stored, $expected]) {
			$this->assertSame($expected, $this->intents(stored: $stored, calls: $calls)->status(id: 'pi-1'), $name);
			$this->assertSame(['register ' . PortalPaymentIntents::REGISTER, 'schema ' . PortalPaymentIntents::SCHEMA, 'find pi-1'], array_slice($calls, 0, 3), $name);
		}
	}//end testEveryIntentIsAsRecorded()

	/**
	 * No id reads nothing; a store that fails is logged and reads as no status.
	 *
	 * @return void
	 */
	public function testNoIdAndAFailingStoreReadNothing(): void {
		$this->assertNull($this->intents(stored: ['paymentStatus' => 'paid'], calls: $calls)->status(id: ''));
		$this->assertSame([], $calls);

		$logged    = [];
		$logger    = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('down'));

		$this->assertNull((new PortalPaymentIntents($container, $logger))->status(id: 'pi-1'));
		$this->assertSame([['Portaliq: a payment intent could not be read', ['reason' => 'down']]], $logged);
	}//end testNoIdAndAFailingStoreReadNothing()

	/**
	 * The service over a store holding one intent.
	 *
	 * @param mixed                   $stored What the store answers.
	 * @param array<int, string>|null $calls  Every store call, set by reference.
	 *
	 * @return PortalPaymentIntents
	 */
	private function intents(mixed $stored, ?array &$calls): PortalPaymentIntents {
		$calls   = [];
		$service = new class($stored, $calls) {
			public function __construct(private mixed $stored, private array &$calls) {
			}

			public function setRegister(string $register): void {
				$this->calls[] = 'register ' . $register;
			}

			public function setSchema(string $schema): void {
				$this->calls[] = 'schema ' . $schema;
			}

			public function find(string $id, bool $_rbac = true, bool $_multitenancy = true): mixed {
				$this->calls[] = 'find ' . $id . ($_rbac === false && $_multitenancy === false ? '' : ' scoped');
				return $this->stored;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($service);

		return new PortalPaymentIntents($container, $this->createMock(LoggerInterface::class));
	}//end intents()
}//end class
