<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\NSEC3PARAMRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionClass;

class NSEC3PARAMRecordValidatorTest extends TestCase
{
    private NSEC3PARAMRecordValidator $validator;
    private ConfigurationManager $configMock;
    private TTLValidator $ttlValidatorMock;
    private HostnameValidator $hostnameValidatorMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);

        // Create mocks for TTLValidator and HostnameValidator
        $this->ttlValidatorMock = $this->createMock(TTLValidator::class);
        $this->hostnameValidatorMock = $this->createMock(HostnameValidator::class);

        // Set up default successful validation for hostname and TTL
        $this->hostnameValidatorMock->method('validate')
            ->willReturn(ValidationResult::success(['hostname' => 'example.com']));

        $this->ttlValidatorMock->method('validate')
            ->willReturn(ValidationResult::success(3600));

        // Create validator with config
        $this->validator = new NSEC3PARAMRecordValidator($this->configMock);

        // Inject mock dependencies using reflection
        $reflection = new ReflectionClass($this->validator);

        $ttlProperty = $reflection->getProperty('ttlValidator');
        $ttlProperty->setAccessible(true);
        $ttlProperty->setValue($this->validator, $this->ttlValidatorMock);

        $hostnameProperty = $reflection->getProperty('hostnameValidator');
        $hostnameProperty->setAccessible(true);
        $hostnameProperty->setValue($this->validator, $this->hostnameValidatorMock);
    }

    /**
     * Test validation with valid NSEC3PARAM content
     */
    public function testValidateWithValidNSEC3PARAMContent(): void
    {
        $result = $this->validator->validate(
            '1 0 10 AB12CD',                                  // content
            'example.com',                                    // name
            '',                                               // prio (empty as not used for NSEC3PARAM)
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('1 0 10 AB12CD', $data['content']);
        $this->assertEquals(3600, $data['ttl']);
        $this->assertEquals(0, $data['priority']);
        $this->assertEquals('example.com', $data['name']);

        // Check for extracted field values
        $this->assertEquals(1, $data['algorithm']);
        $this->assertEquals(0, $data['flags']);
        $this->assertEquals(10, $data['iterations']);
        $this->assertEquals('AB12CD', $data['salt']);

        // Check for warnings array
        $this->assertTrue($result->hasWarnings());
        $this->assertIsArray($result->getWarnings());
        $this->assertNotEmpty($result->getWarnings());

        // Check for RFC 9276 iteration warning (iterations > 0)
        $iterationWarningFound = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'RFC 9276 recommends using 0 iterations') !== false) {
                $iterationWarningFound = true;
                break;
            }
        }
        $this->assertTrue($iterationWarningFound, 'Warning about iterations > 0 not found');

        // Check for salt warning (salt is not empty)
        $saltWarningFound = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'RFC 9276 recommends NOT using a salt') !== false) {
                $saltWarningFound = true;
                break;
            }
        }
        $this->assertTrue($saltWarningFound, 'Warning about non-empty salt not found');
    }

    /**
     * Test validation with empty salt
     */
    public function testValidateWithEmptySalt(): void
    {
        $result = $this->validator->validate(
            '1 0 5 -',                                        // content with empty salt
            'example.com',                                    // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('1 0 5 -', $data['content']);
        $this->assertEquals(3600, $data['ttl']);
        $this->assertEquals(0, $data['priority']);
    }

    /**
     * Test validation with empty content
     */
    public function testValidateWithEmptyContent(): void
    {
        $result = $this->validator->validate(
            '',                                               // empty content
            'example.com',                                    // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('cannot be empty', $result->getFirstError());
    }

    /**
     * Test validation with too few fields
     */
    public function testValidateWithTooFewFields(): void
    {
        $result = $this->validator->validate(
            '1 0 5',                                          // missing salt
            'example.com',                                    // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('must contain exactly', $result->getFirstError());
    }

    /**
     * Test validation with too many fields
     */
    public function testValidateWithTooManyFields(): void
    {
        $result = $this->validator->validate(
            '1 0 5 - extrafield',                             // too many fields
            'example.com',                                    // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('must contain exactly', $result->getFirstError());
    }

    /**
     * Test validation with invalid hash algorithm
     */
    public function testValidateWithInvalidAlgorithm(): void
    {
        $result = $this->validator->validate(
            '2 0 5 -',                                        // invalid algorithm (2)
            'example.com',                                    // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('hash algorithm must be 1', $result->getFirstError());
    }

    /**
     * Test validation with invalid flags
     */
    public function testValidateWithInvalidFlags(): void
    {
        $result = $this->validator->validate(
            '1 256 5 -',                                      // invalid flags (256)
            'example.com',                                    // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('flags must be between', $result->getFirstError());
    }

    /**
     * Test validation with too many iterations
     */
    public function testValidateWithTooManyIterations(): void
    {
        $result = $this->validator->validate(
            '1 0 3000 -',                                     // too many iterations
            'example.com',                                    // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('iterations must be between', $result->getFirstError());
    }

    /**
     * Test validation with invalid salt
     */
    public function testValidateWithInvalidSalt(): void
    {
        $result = $this->validator->validate(
            '1 0 5 GZ',                                       // invalid salt (non-hex)
            'example.com',                                    // name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('salt must be -', $result->getFirstError());
    }

    /**
     * Test validation with invalid TTL
     */
    public function testValidateWithInvalidTtl(): void
    {
        // Set up TTL validator to fail
        $this->ttlValidatorMock = $this->createMock(TTLValidator::class);
        $this->ttlValidatorMock->expects($this->once())
            ->method('validate')
            ->with(-1, 86400)
            ->willReturn(ValidationResult::failure('TTL must be a positive number'));

        // Inject mock validator
        $reflection = new ReflectionClass($this->validator);
        $ttlProperty = $reflection->getProperty('ttlValidator');
        $ttlProperty->setAccessible(true);
        $ttlProperty->setValue($this->validator, $this->ttlValidatorMock);

        $result = $this->validator->validate(
            '1 0 5 -',                                        // content
            'example.com',                                    // name
            '',                                               // prio
            -1,                                               // invalid ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('TTL', $result->getFirstError());
    }

    /**
     * Test validation with default TTL
     */
    public function testValidateWithDefaultTtl(): void
    {
        // Set up TTL validator to return default TTL
        $this->ttlValidatorMock = $this->createMock(TTLValidator::class);
        $this->ttlValidatorMock->expects($this->once())
            ->method('validate')
            ->with('', 86400)
            ->willReturn(ValidationResult::success(86400));

        // Inject mock validator
        $reflection = new ReflectionClass($this->validator);
        $ttlProperty = $reflection->getProperty('ttlValidator');
        $ttlProperty->setAccessible(true);
        $ttlProperty->setValue($this->validator, $this->ttlValidatorMock);

        $result = $this->validator->validate(
            '1 0 5 -',                                        // content
            'example.com',                                    // name
            '',                                               // prio
            '',                                               // empty ttl, should use default
            86400                                             // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('1 0 5 -', $data['content']);
        $this->assertEquals(86400, $data['ttl']);
        $this->assertEquals(0, $data['priority']);
    }

    /**
     * Test validation with invalid hostname
     */
    public function testValidateWithInvalidHostname(): void
    {
        // Set up hostname validator to fail
        $this->hostnameValidatorMock = $this->createMock(HostnameValidator::class);
        $this->hostnameValidatorMock->expects($this->once())
            ->method('validate')
            ->with('invalid..hostname', true)
            ->willReturn(ValidationResult::failure('Invalid hostname format'));

        // Inject mock validator
        $reflection = new ReflectionClass($this->validator);
        $hostnameProperty = $reflection->getProperty('hostnameValidator');
        $hostnameProperty->setAccessible(true);
        $hostnameProperty->setValue($this->validator, $this->hostnameValidatorMock);

        $result = $this->validator->validate(
            '1 0 5 -',                                        // content
            'invalid..hostname',                              // invalid name
            '',                                               // prio
            3600,                                             // ttl
            86400                                             // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid hostname', $result->getFirstError());
    }

    /**
     * Test validation with RFC 9276 best practices (0 iterations, no salt)
     */
    public function testValidateWithRfc9276BestPractices(): void
    {
        $result = $this->validator->validate(
            '1 0 0 -',                                      // RFC 9276 best practices
            'example.com',                                  // zone apex
            '',                                             // prio
            3600,                                           // ttl
            86400                                           // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();

        // Check field values
        $this->assertEquals(1, $data['algorithm']);
        $this->assertEquals(0, $data['flags']);
        $this->assertEquals(0, $data['iterations']);
        $this->assertEquals('-', $data['salt']);

        // Check warnings
        $this->assertTrue($result->hasWarnings());

        // Iteration and salt warnings should NOT be present for RFC 9276 compliant records
        $iterationWarningFound = false;
        $saltWarningFound = false;

        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'RFC 9276 recommends using 0 iterations') !== false) {
                $iterationWarningFound = true;
            }
            if (strpos($warning, 'RFC 9276 recommends NOT using a salt') !== false) {
                $saltWarningFound = true;
            }
        }

        $this->assertFalse($iterationWarningFound, 'Warning about iterations should not be present for 0 iterations');
        $this->assertFalse($saltWarningFound, 'Warning about salt should not be present for empty salt (-)');
    }

    /**
     * Test validation with subdomain (should generate apex warning)
     */
    public function testValidateWithSubdomain(): void
    {
        // Set up hostname validator to return the exact hostname for subdomain
        $this->hostnameValidatorMock = $this->createMock(HostnameValidator::class);
        $this->hostnameValidatorMock->method('validate')
            ->willReturn(ValidationResult::success(['hostname' => 'sub.example.com']));

        // Inject the mock validator
        $reflection = new ReflectionClass($this->validator);
        $hostnameProperty = $reflection->getProperty('hostnameValidator');
        $hostnameProperty->setAccessible(true);
        $hostnameProperty->setValue($this->validator, $this->hostnameValidatorMock);

        $result = $this->validator->validate(
            '1 0 0 -',                                      // content
            'sub.example.com',                              // subdomain (not zone apex)
            '',                                             // prio
            3600,                                           // ttl
            86400                                           // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();

        // Verify that the domain name was passed correctly
        $this->assertEquals('sub.example.com', $data['name']);

        // Check for zone apex warning
        $apexWarningFound = false;
        foreach ($result->getWarnings() as $warning) {
            if (strpos($warning, 'NSEC3PARAM records MUST only be present at the zone apex') !== false) {
                $apexWarningFound = true;
                break;
            }
        }

        $this->assertTrue($apexWarningFound, 'Warning about zone apex not found for subdomain');
    }
}
