<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\Security\ISecureRandom;

/**
 * A fake OpenRegister store the identity services can be driven against: it
 * answers a read the way PortalObjectReader does (narrow on the scope field,
 * then the declared filter) and records a write the way PortalObjectWriter
 * does. Every double is built with `onlyMethods`, so it can never answer a
 * method the real class does not have.
 */
trait PortalIdentityStoreTrait {
	/**
	 * The rows the fake store holds, keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * A reader over the fake store.
	 *
	 * @return PortalObjectReader
	 */
	private function fakeReader(): PortalObjectReader {
		$reader = $this->getMockBuilder(PortalObjectReader::class)
			->disableOriginalConstructor()
			->onlyMethods(['readCollection'])
			->getMock();
		$reader->method('readCollection')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation = '', int $limit = 200, string $scopeClaim = '', string $contributingApp = '', mixed $via = null, string $audience = '', mixed $fields = null, array $filter = []): array {
				$matches = [];
				foreach ($this->rows as $row) {
					if (($row['_schema'] ?? '') !== $schema) {
						continue;
					}

					if ($scopeField !== '' && ($row[$scopeField] ?? null) !== $subjectRef) {
						continue;
					}

					if ($organisation !== '' && ($row['organisation'] ?? null) !== $organisation) {
						continue;
					}

					foreach ($filter as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							continue 2;
						}
					}

					$matches[] = $row;
				}

				return $matches;
			}
		);

		return $reader;
	}//end fakeReader()

	/**
	 * A writer over the fake store.
	 *
	 * @return PortalObjectWriter
	 */
	private function fakeWriter(): PortalObjectWriter {
		$writer = $this->getMockBuilder(PortalObjectWriter::class)
			->disableOriginalConstructor()
			->onlyMethods(['createObject', 'updateObject'])
			->getMock();
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data): array {
				$uuid = 'uuid-' . (count($this->rows) + 1);
				$data['uuid'] = $uuid;
				$data['_schema'] = $schema;
				if ($organisation !== '') {
					$data['organisation'] = $organisation;
				}

				$this->rows[$uuid] = $data;
				return $data;
			}
		);
		$writer->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data): ?array {
				if (isset($this->rows[$id]) === false) {
					return null;
				}

				if ($scopeField !== '' && ($this->rows[$id][$scopeField] ?? null) !== $subjectRef) {
					// The real writer re-reads the row and refuses when it is
					// not the caller's; the fake refuses the same way.
					return null;
				}

				$this->rows[$id] = array_merge($this->rows[$id], $data);
				return $this->rows[$id];
			}
		);

		return $writer;
	}//end fakeWriter()

	/**
	 * A random double handing out predictable secrets.
	 *
	 * @return ISecureRandom
	 */
	private function fakeRandom(): ISecureRandom {
		$counter = 0;
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			function () use (&$counter): string {
				$counter++;
				return 'secret-' . $counter;
			}
		);

		return $random;
	}//end fakeRandom()

	/**
	 * Put a row in the fake store directly.
	 *
	 * @param string $schema The schema it belongs to.
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The row's uuid.
	 */
	private function seedRow(string $schema, array $row): string {
		$uuid = 'uuid-' . (count($this->rows) + 1);
		$row['uuid'] = $uuid;
		$row['_schema'] = $schema;
		$this->rows[$uuid] = $row;

		return $uuid;
	}//end seedRow()

	/**
	 * The stored rows of one schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function storedRows(string $schema): array {
		$out = [];
		foreach ($this->rows as $row) {
			if (($row['_schema'] ?? '') === $schema) {
				$out[] = $row;
			}
		}

		return $out;
	}//end storedRows()
}//end trait
