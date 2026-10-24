<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\CmsRows;
use OCA\Portaliq\Service\PortalRegisterContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Scoped CMS reads through OpenRegister's object service.
 */
#[CoversClass(CmsRows::class)]
class CmsRowsTest extends TestCase {
	/**
	 * Build CmsRows over an object service that returns the given rows.
	 *
	 * @param mixed $rows      What findAll returns.
	 * @param bool  $applies   Whether the register context applies.
	 * @param bool  $throws    Whether the container throws.
	 * @param bool  $expectLog Whether an error is expected to be logged.
	 *
	 * @return CmsRows
	 */
	private function rows(mixed $rows, bool $applies=true, bool $throws=false, bool $expectLog=false): CmsRows {
		$service = new class($rows) {
			public function __construct(private mixed $rows) {
			}

			public function findAll(array $config, bool $_rbac, bool $_multitenancy): mixed {
				return $this->rows;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		if ($throws === true) {
			$container->method('get')->willThrowException(new \RuntimeException('no OR'));
		} else {
			$container->method('get')->willReturn($service);
		}

		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturn($applies);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($expectLog === true ? $this->once() : $this->never())->method('error');

		return new CmsRows($container, $logger, $context);
	}//end rows()

	/**
	 * An unscoped query is refused and logged.
	 *
	 * @return void
	 */
	public function testUnscopedQueryIsRefused(): void {
		$this->assertSame([], $this->rows([['a' => 1]], true, false, true)->query('page', []));
	}//end testUnscopedQueryIsRefused()

	/**
	 * Rows come back as arrays, objects through jsonSerialize.
	 *
	 * @return void
	 */
	public function testQueryReturnsRows(): void {
		$object = new class() implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 'o1'];
			}
		};

		$out = $this->rows([['id' => 'a'], $object])->query('page', ['portal' => 'zuid']);

		$this->assertSame([['id' => 'a'], ['id' => 'o1']], $out);
		$this->assertSame([['id' => 'a']], $this->rows([['id' => 'a']])->query('page', ['organisation' => 'o']));
	}//end testQueryReturnsRows()

	/**
	 * A failing context, a throwing container or a non-array answer is empty.
	 *
	 * @return void
	 */
	public function testFailuresAreEmpty(): void {
		$this->assertSame([], $this->rows([['a' => 1]], false)->query('page', ['portal' => 'z']));
		$this->assertSame([], $this->rows(null, true, true, true)->query('page', ['portal' => 'z']));
		$this->assertSame([], $this->rows('nope')->query('page', ['portal' => 'z']));
	}//end testFailuresAreEmpty()

	/**
	 * The row id is found on the row or its @self block.
	 *
	 * @return void
	 */
	public function testRowId(): void {
		$r = $this->rows([]);

		$this->assertSame('1', $r->rowId(['id' => 1]));
		$this->assertSame('u', $r->rowId(['id' => '', 'uuid' => 'u']));
		$this->assertSame('si', $r->rowId(['@self' => ['id' => 'si']]));
		$this->assertSame('su', $r->rowId(['@self' => ['uuid' => 'su']]));
		$this->assertNull($r->rowId(['@self' => 'x', 'id' => []]));
	}//end testRowId()
}//end class
