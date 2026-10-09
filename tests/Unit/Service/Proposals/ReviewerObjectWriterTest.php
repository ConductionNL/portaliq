<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Proposals;

use OCA\Portaliq\Service\Proposals\ReviewerObjectWriter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * change-proposal-queue: accepting a proposal writes only the accepted
 * properties onto the record, as the reviewer. The values are a few
 * properties, never the whole record, so they go through a patch: a save
 * would take them as the whole object and OpenRegister refuses it over the
 * record's other required properties.
 *
 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
 */
class ReviewerObjectWriterTest extends TestCase {

	/**
	 * What the fake object service was asked, by method.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $calls = [];

	public function testTheAcceptedValuesArePatchedOntoTheRecordAsTheReviewer(): void {
		$writer = $this->writer(throws: false);

		$this->assertTrue($writer->write(register: 'portaliq', schema: 'portalCase', id: 'zaak-1', values: ['toelichting' => 'Nieuw']));

		$this->assertArrayNotHasKey('saveObject', $this->calls);
		$this->assertSame('zaak-1', $this->calls['patchObject']['objectId']);
		$this->assertSame(['toelichting' => 'Nieuw'], $this->calls['patchObject']['data']);
		$this->assertSame('portaliq', $this->calls['patchObject']['register']);
		$this->assertSame('portalCase', $this->calls['patchObject']['schema']);
		$this->assertTrue($this->calls['patchObject']['_rbac']);
		$this->assertTrue($this->calls['patchObject']['_multitenancy']);

	}//end testTheAcceptedValuesArePatchedOntoTheRecordAsTheReviewer()

	public function testARefusedPatchIsNotWritten(): void {
		$writer = $this->writer(throws: true);

		$this->assertFalse($writer->write(register: 'portaliq', schema: 'portalCase', id: 'zaak-1', values: ['toelichting' => 'Nieuw']));

	}//end testARefusedPatchIsNotWritten()

	public function testNothingToWriteIsNoWrite(): void {
		$writer = $this->writer(throws: false);

		$this->assertFalse($writer->write(register: 'portaliq', schema: 'portalCase', id: 'zaak-1', values: []));
		$this->assertSame([], $this->calls);

	}//end testNothingToWriteIsNoWrite()

	/**
	 * The writer over a fake object service that records what it is asked.
	 *
	 * @param bool $throws Whether the write is refused.
	 *
	 * @return ReviewerObjectWriter
	 */
	private function writer(bool $throws): ReviewerObjectWriter {
		$objectService = new class($this->calls, $throws) {
			/**
			 * @param array<string, array<string, mixed>> $calls The recorded calls, by reference.
			 * @param bool $throws Whether the write is refused.
			 */
			public function __construct(private array &$calls, private readonly bool $throws) {
			}

			/**
			 * @param array<string, mixed> $object The object.
			 * @param mixed ...$rest The other arguments.
			 *
			 * @return object
			 */
			public function saveObject(array $object, mixed ...$rest): object {
				$this->calls['saveObject'] = ['object' => $object] + $rest;
				if ($this->throws === true) {
					throw new RuntimeException('refused');
				}

				return new \stdClass();
			}

			/**
			 * @param string $objectId The record.
			 * @param array<string, mixed> $data The values.
			 * @param mixed $register The register.
			 * @param mixed $schema The schema.
			 * @param bool $_rbac Whether RBAC applies.
			 * @param bool $_multitenancy Whether multitenancy applies.
			 *
			 * @return object
			 */
			public function patchObject(string $objectId, array $data, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): object {
				$this->calls['patchObject'] = [
					'objectId' => $objectId,
					'data' => $data,
					'register' => $register,
					'schema' => $schema,
					'_rbac' => $_rbac,
					'_multitenancy' => $_multitenancy,
				];
				if ($this->throws === true) {
					throw new RuntimeException('refused');
				}

				return new \stdClass();
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new ReviewerObjectWriter($container, $this->createMock(LoggerInterface::class));
	}//end writer()

}//end class
