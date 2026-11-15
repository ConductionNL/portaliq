<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalFileFieldPolicy;
use OCA\Portaliq\Service\PortalSchemaReader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The rules of an upload into a declared file field
 * (assignment-portal-file-upload): which field qualifies, the create window,
 * accept and size, and how a new reference joins the value already there.
 *
 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
 */
class PortalFileFieldPolicyTest extends TestCase {
	/**
	 * The fixed "now": 2026-09-27T12:00:00Z.
	 */
	private const NOW = 1790510400;

	/**
	 * A policy on a fixed clock.
	 *
	 * @return PortalFileFieldPolicy
	 */
	private function policy(?array $schema = null): PortalFileFieldPolicy {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$schemaReader = $this->createMock(PortalSchemaReader::class);
		$schemaReader->method('readSchema')->willReturn($schema);
		return new PortalFileFieldPolicy($time, $schemaReader);
	}//end policy()

	/**
	 * Only a whitelisted field declared `type: file` on a create or update
	 * action qualifies.
	 *
	 * @return void
	 */
	public function testOnlyADeclaredFileFieldQualifies(): void {
		$action = [
			'type' => 'create',
			'fields' => ['assignmentId', 'attachmentRefs'],
			'fieldConfigs' => ['attachmentRefs' => ['type' => 'file', 'multiple' => true], 'assignmentId' => ['label' => 'x']],
		];

		$this->assertSame(['type' => 'file', 'multiple' => true], $this->policy()->fileConfig($action, 'attachmentRefs'));
		$this->assertNull($this->policy()->fileConfig($action, 'assignmentId'));
		$this->assertNull($this->policy()->fileConfig($action, 'learnerRef'));

		$endpoint = array_merge($action, ['type' => 'endpoint']);
		$this->assertNull($this->policy()->fileConfig($endpoint, 'attachmentRefs'));

		$notWhitelisted = array_merge($action, ['fields' => ['assignmentId']]);
		$this->assertNull($this->policy()->fileConfig($notWhitelisted, 'attachmentRefs'));
	}//end testOnlyADeclaredFileFieldQualifies()

	/**
	 * A create action's window is thirty minutes from `@self.created`; a
	 * missing or unreadable timestamp closes it; an update action has none.
	 *
	 * @return void
	 */
	public function testTheCreateWindow(): void {
		$policy = $this->policy();
		$minuteAgo = ['@self' => ['created' => gmdate('c', self::NOW - 60)]];
		$hourAgo = ['@self' => ['created' => gmdate('c', self::NOW - 3600)]];

		$this->assertTrue($policy->windowOpen('create', $minuteAgo));
		$this->assertFalse($policy->windowOpen('create', $hourAgo));
		$this->assertFalse($policy->windowOpen('create', []));
		$this->assertFalse($policy->windowOpen('create', ['@self' => ['created' => 'not a date']]));
		$this->assertFalse($policy->windowOpen('create', ['@self' => ['created' => gmdate('c', self::NOW + 600)]]));
		$this->assertTrue($policy->windowOpen('update', $hourAgo));
	}//end testTheCreateWindow()

	/**
	 * `accept` matches an extension against the name, or a MIME entry against
	 * the detected type; any match passes and no `accept` admits anything.
	 *
	 * @return void
	 */
	public function testAcceptMatchesExtensionOrMime(): void {
		$policy = $this->policy();
		$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
		$text = "Mijn werkstuk over de Tachtigjarige Oorlog.\n";

		$this->assertNull($policy->refusal(['accept' => ['.pdf']], 'Essay.PDF', $pdf));
		$this->assertSame(PortalFileFieldPolicy::ERROR_TYPE_REFUSED, $policy->refusal(['accept' => ['.pdf']], 'run.exe', $text));
		$this->assertNull($policy->refusal(['accept' => ['application/pdf']], 'no-extension', $pdf));
		$this->assertNull($policy->refusal(['accept' => ['text/*']], 'notes.bin', $text));
		$this->assertSame(PortalFileFieldPolicy::ERROR_TYPE_REFUSED, $policy->refusal(['accept' => ['image/*']], 'notes.txt', $text));
		$this->assertNull($policy->refusal([], 'anything.xyz', $text));
		$this->assertSame(PortalFileFieldPolicy::ERROR_TYPE_REFUSED, $policy->refusal(['accept' => ['.pdf']], 'noextension', $text));
	}//end testAcceptMatchesExtensionOrMime()

	/**
	 * An extension entry needs bytes that fit the name: an HTML page or an
	 * SVG renamed to `.pdf` is refused, and for an extension the policy has
	 * no type list for, only active content is.
	 *
	 * @return void
	 */
	public function testAnExtensionEntryNeedsBytesThatFitTheName(): void {
		$policy = $this->policy();
		$html = "<!DOCTYPE html><html><body><script>alert(1)</script></body></html>\n";
		$svg = "<svg xmlns=\"http://www.w3.org/2000/svg\"><script>alert(1)</script></svg>\n";
		$text = "Mijn werkstuk over de Tachtigjarige Oorlog.\n";
		$csv = "naam,klas\nNoor,5a\n";

		$this->assertSame(PortalFileFieldPolicy::ERROR_TYPE_REFUSED, $policy->refusal(['accept' => ['.pdf']], 'essay.pdf', $html));
		$this->assertSame(PortalFileFieldPolicy::ERROR_TYPE_REFUSED, $policy->refusal(['accept' => ['.pdf']], 'essay.pdf', $svg));
		$this->assertSame(PortalFileFieldPolicy::ERROR_TYPE_REFUSED, $policy->refusal(['accept' => ['.pdf', '.txt']], 'essay.txt', $html));
		$this->assertNull($policy->refusal(['accept' => ['.txt']], 'notes.txt', $text));
		$this->assertNull($policy->refusal(['accept' => ['.txt', '.csv']], 'klas.csv', $csv));
		$this->assertNull($policy->refusal(['accept' => ['.txt']], 'klas.txt', $csv));
		$this->assertNull($policy->refusal(['accept' => ['.svg']], 'logo.svg', $svg));

		// An extension without a type list passes on its name, unless the bytes are active content.
		$this->assertNull($policy->refusal(['accept' => ['.odt']], 'werkstuk.odt', $text));
		$this->assertSame(PortalFileFieldPolicy::ERROR_TYPE_REFUSED, $policy->refusal(['accept' => ['.odt']], 'werkstuk.odt', $html));
	}//end testAnExtensionEntryNeedsBytesThatFitTheName()

	/**
	 * Plain text may sniff as any text type or JSON, never as active content;
	 * a wildcard MIME entry never admits active content either.
	 *
	 * @return void
	 */
	public function testTextAndWildcardEntriesNeverAdmitActiveContent(): void {
		$policy = $this->policy();
		$code = "#include <stdio.h>\nint main(void) { return 0; }\n";
		$json = "{\"naam\": \"Noor\", \"klas\": \"5a\"}";
		$svg = "<svg xmlns=\"http://www.w3.org/2000/svg\"><script>alert(1)</script></svg>\n";
		$html = "<!DOCTYPE html><html><body>x</body></html>\n";
		$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);

		$this->assertNull($policy->refusal(['accept' => ['.txt']], 'notes.txt', $code));
		$this->assertNull($policy->refusal(['accept' => ['.txt']], 'notes.txt', $json));
		$this->assertSame(PortalFileFieldPolicy::ERROR_TYPE_REFUSED, $policy->refusal(['accept' => ['.txt', '.csv']], 'klas.csv', $html));

		$this->assertNull($policy->refusal(['accept' => ['image/*']], 'pixel.png', (string)$png));
		$this->assertSame(PortalFileFieldPolicy::ERROR_TYPE_REFUSED, $policy->refusal(['accept' => ['image/*']], 'logo.svg', $svg));
		$this->assertSame(PortalFileFieldPolicy::ERROR_TYPE_REFUSED, $policy->refusal(['accept' => ['text/*']], 'page.txt', $html));
		$this->assertNull($policy->refusal(['accept' => ['image/svg+xml']], 'logo.svg', $svg), 'a field that names the type still takes it');
	}//end testTextAndWildcardEntriesNeverAdmitActiveContent()

	/**
	 * A file above `maxSizeMb` is refused; the default is twenty megabytes.
	 *
	 * @return void
	 */
	public function testSizeAboveTheLimitIsRefused(): void {
		$policy = $this->policy();
		$twoMb = str_repeat('a', (2 * 1024 * 1024));

		$this->assertSame(PortalFileFieldPolicy::ERROR_TOO_LARGE, $policy->refusal(['maxSizeMb' => 1, 'accept' => ['.pdf']], 'essay.pdf', $twoMb));
		$this->assertNull($policy->refusal(['maxSizeMb' => 3], 'essay.txt', $twoMb));
		$this->assertNull($policy->refusal([], 'essay.txt', $twoMb));
	}//end testSizeAboveTheLimitIsRefused()

	/**
	 * A multiple array field appends; a single field replaces; a full field
	 * refuses; an unreadable schema falls back to `multiple`.
	 *
	 * @return void
	 */
	public function testTheMergedValue(): void {
		$policy = $this->policy();
		$multiple = ['multiple' => true];
		$single = ['multiple' => false];

		$this->assertSame(['4702', '4711'], $policy->mergedValue($multiple, ['4702', '', 7], '4711', true));
		$this->assertSame(['4711'], $policy->mergedValue($multiple, 'garbage', '4711', true));
		$this->assertSame(['4711'], $policy->mergedValue($single, ['4702'], '4711', true));
		$this->assertSame('4711', $policy->mergedValue($single, '4702', '4711', false));
		$this->assertSame('4711', $policy->mergedValue($multiple, '4702', '4711', false));
		$this->assertSame(['4711'], $policy->mergedValue($multiple, null, '4711', null));
		$this->assertSame('4711', $policy->mergedValue($single, null, '4711', null));

		$full = array_map('strval', range(1, PortalFileFieldPolicy::MAX_FILES_PER_FIELD));
		$this->assertNull($policy->mergedValue($multiple, $full, '4711', true));
		$this->assertFalse($policy->hasRoom($multiple, $full, true));
		$this->assertTrue($policy->hasRoom($single, $full, true));
		$this->assertTrue($policy->hasRoom($multiple, array_slice($full, 1), true));
	}//end testTheMergedValue()

	/**
	 * The schema property type decides the value shape, when the schema says.
	 *
	 * @return void
	 */
	public function testIsArrayProperty(): void {
		$schema = ['properties' => ['attachmentRefs' => ['type' => 'array'], 'attachmentRef' => ['type' => 'string']]];

		$this->assertTrue($this->policy($schema)->isArrayProperty('submission', 'attachmentRefs'));
		$this->assertFalse($this->policy($schema)->isArrayProperty('submission', 'attachmentRef'));
		$this->assertNull($this->policy($schema)->isArrayProperty('submission', 'unknown'));
		$this->assertNull($this->policy(null)->isArrayProperty('submission', 'attachmentRefs'));
	}//end testIsArrayProperty()

	/**
	 * The multipart part is read with its name reduced to a basename; a
	 * missing or failed part is nothing.
	 *
	 * @return void
	 */
	public function testReadUpload(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'pq');
		file_put_contents($tmp, 'werkstuk');

		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->willReturn(['tmp_name' => $tmp, 'error' => 0, 'name' => '../../etc/Essay.pdf']);
		$this->assertSame(['name' => 'Essay.pdf', 'content' => 'werkstuk'], $this->policy()->readUpload($request));

		$failed = $this->createMock(IRequest::class);
		$failed->method('getUploadedFile')->willReturn(['tmp_name' => $tmp, 'error' => 4]);
		$this->assertNull($this->policy()->readUpload($failed));

		$absent = $this->createMock(IRequest::class);
		$absent->method('getUploadedFile')->willReturn([]);
		$this->assertNull($this->policy()->readUpload($absent));

		unlink($tmp);
	}//end testReadUpload()
}//end class
