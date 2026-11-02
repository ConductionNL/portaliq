<?php

/**
 * ScopedCollectionReaderGoldenTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use ReflectionMethod;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The classes that only read scoped collections take the portal object reader
 * wherever they took it before: the same constructor parameters, and every
 * reader parameter still accepts a PortalObjectReader.
 *
 * @covers \OCA\Portaliq\Service\PortalObjectReader
 */
class ScopedCollectionReaderGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/scoped-collection-reader-users.json';

	/**
	 * The classes whose constructors are pinned.
	 */
	private const USERS = [
		'OCA\\Portaliq\\BackgroundJob\\NotificationDispatchJob',
		'OCA\\Portaliq\\BackgroundJob\\PortalTaskDeliveryJob',
		'OCA\\Portaliq\\Listener\\PortalDigitalPostDeliveredListener',
		'OCA\\Portaliq\\Portal\\PortalContributionProvider',
		'OCA\\Portaliq\\Service\\Cms\\AccessibilityMeasurements',
		'OCA\\Portaliq\\Service\\Cms\\PortalDomainVerifier',
		'OCA\\Portaliq\\Service\\Cms\\PortalHomePage',
		'OCA\\Portaliq\\Service\\Identity\\ConfirmedAddressJoin',
		'OCA\\Portaliq\\Service\\Identity\\ContactInvitationLink',
		'OCA\\Portaliq\\Service\\Identity\\ContactRows',
		'OCA\\Portaliq\\Service\\Identity\\EmailLink\\EmailLinkEligibility',
		'OCA\\Portaliq\\Service\\Identity\\EmailLink\\EmailLinkSender',
		'OCA\\Portaliq\\Service\\Identity\\EmailLink\\EmailLinkTokens',
		'OCA\\Portaliq\\Service\\Identity\\PortalAccountActivationService',
		'OCA\\Portaliq\\Service\\Identity\\PortalAccountLookup',
		'OCA\\Portaliq\\Service\\Identity\\PortalAccountPresence',
		'OCA\\Portaliq\\Service\\Identity\\PortalContactService',
		'OCA\\Portaliq\\Service\\Identity\\PortalMandateService',
		'OCA\\Portaliq\\Service\\Identity\\PortalReferenceCaseService',
		'OCA\\Portaliq\\Service\\Identity\\PortalReferenceLinkService',
		'OCA\\Portaliq\\Service\\Identity\\PortalSelfServiceService',
		'OCA\\Portaliq\\Service\\Identity\\WaitingAccountInvitation',
		'OCA\\Portaliq\\Service\\Identity\\WaitingAccountSecret',
		'OCA\\Portaliq\\Service\\Intake\\DestinationLookup',
		'OCA\\Portaliq\\Service\\Intake\\LandingSubmissionDrain',
		'OCA\\Portaliq\\Service\\Intake\\PortalCatalogueReader',
		'OCA\\Portaliq\\Service\\Intake\\PortalDraftDrain',
		'OCA\\Portaliq\\Service\\Intake\\PortalDraftStore',
		'OCA\\Portaliq\\Service\\LandingPageProvisioningService',
		'OCA\\Portaliq\\Service\\LeafGuardianAudienceReader',
		'OCA\\Portaliq\\Service\\Notifications\\MessageBoxDeliveries',
		'OCA\\Portaliq\\Service\\Notifications\\NotificationChannels',
		'OCA\\Portaliq\\Service\\OidcStateStoreService',
		'OCA\\Portaliq\\Service\\Plans\\PlanTemplates',
		'OCA\\Portaliq\\Service\\PollService',
		'OCA\\Portaliq\\Service\\PortalAccountService',
		'OCA\\Portaliq\\Service\\PortalCaseListReader',
		'OCA\\Portaliq\\Service\\PortalCaseTypeCatalogue',
		'OCA\\Portaliq\\Service\\PortalInboxReader',
		'OCA\\Portaliq\\Service\\Proposals\\ProposalQueueReader',
		'OCA\\Portaliq\\Service\\Reports\\ReportThreadService',
		'OCA\\Portaliq\\Service\\Reports\\RevealService',
		'OCA\\Portaliq\\Service\\Signin\\PortalSigninSettings',
		'OCA\\Portaliq\\Service\\Theme\\PortalThemeChoice',
	];

	/**
	 * Every pinned constructor still takes the same parameters, and each
	 * parameter that took the reader still accepts one.
	 *
	 * @return void
	 */
	public function testTheReaderUsersTakeTheReaderAsBefore(): void {
		$actual = [];
		foreach (self::USERS as $class) {
			$actual[$class] = $this->constructorShape(class: $class);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(filename: self::GOLDEN, data: json_encode(value: $actual, flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(expected: json_decode(json: (string)file_get_contents(filename: self::GOLDEN), associative: true), actual: $actual);
	}//end testTheReaderUsersTakeTheReaderAsBefore()

	/**
	 * The reader fulfils the scoped read contract with the very same
	 * parameters, types and defaults.
	 *
	 * @return void
	 */
	public function testTheReaderFulfilsTheScopedReadContract(): void {
		$this->assertTrue(condition: is_a(object_or_class: PortalObjectReader::class, class: ScopedCollectionReaderInterface::class, allow_string: true));
		$this->assertSame(
			expected: $this->signature(method: new ReflectionMethod(objectOrMethod: PortalObjectReader::class, method: 'readCollection')),
			actual: $this->signature(method: new ReflectionMethod(objectOrMethod: ScopedCollectionReaderInterface::class, method: 'readCollection'))
		);
	}//end testTheReaderFulfilsTheScopedReadContract()

	/**
	 * A method's parameters as name, type and default, plus its return type.
	 *
	 * @param ReflectionMethod $method The method.
	 *
	 * @return array<int, string> One line per parameter, then the return type.
	 */
	private function signature(ReflectionMethod $method): array {
		$lines = [];
		foreach ($method->getParameters() as $parameter) {
			$default = 'required';
			if ($parameter->isDefaultValueAvailable() === true) {
				$default = var_export(value: $parameter->getDefaultValue(), return: true);
			}

			$lines[] = $parameter->getName() . ':' . (string)$parameter->getType() . '=' . $default;
		}

		$lines[] = 'return:' . (string)$method->getReturnType();
		return $lines;
	}//end signature()

	/**
	 * The constructor parameters of a class: name, position, nullability,
	 * default, and whether a PortalObjectReader fits the declared type.
	 *
	 * @param string $class The class name.
	 *
	 * @return array<int, array<string, mixed>> One entry per parameter.
	 */
	private function constructorShape(string $class): array {
		$shape = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$type    = $parameter->getType();
			$accepts = $type instanceof ReflectionNamedType
				&& $type->isBuiltin() === false
				&& is_a(object_or_class: PortalObjectReader::class, class: $type->getName(), allow_string: true);
			$shape[] = [
				'name'          => $parameter->getName(),
				'position'      => $parameter->getPosition(),
				'allowsNull'    => $parameter->allowsNull(),
				'optional'      => $parameter->isOptional(),
				'promoted'      => $parameter->isPromoted(),
				'acceptsReader' => $accepts,
			];
		}

		return $shape;
	}//end constructorShape()
}//end class
