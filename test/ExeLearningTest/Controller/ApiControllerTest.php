<?php

declare(strict_types=1);

namespace ExeLearningTest\Controller;

use ExeLearning\Controller\ApiController;
use ExeLearning\Service\ElpFileService;
use Laminas\View\Model\JsonModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for ApiController.
 *
 * @covers \ExeLearning\Controller\ApiController
 */
class ApiControllerTest extends TestCase
{
    private ApiController $controller;
    private ElpFileService $elpService;

    protected function setUp(): void
    {
        $this->elpService = $this->createMock(ElpFileService::class);
        $this->controller = new ApiController($this->elpService);
    }

    private function callProtectedMethod(object $object, string $method, array $args = [])
    {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $args);
    }

    // =========================================================================
    // Constructor tests
    // =========================================================================

    public function testConstructorSetsElpService(): void
    {
        $reflection = new ReflectionClass($this->controller);
        $property = $reflection->getProperty('elpService');
        $property->setAccessible(true);

        $this->assertSame($this->elpService, $property->getValue($this->controller));
    }

    // =========================================================================
    // errorResponse() tests
    // =========================================================================

    public function testErrorResponseReturnsJsonModel(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'errorResponse', [400, 'Test error']);

        $this->assertInstanceOf(JsonModel::class, $result);
    }

    public function testErrorResponseContainsSuccessFalse(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'errorResponse', [400, 'Test error']);

        $variables = $result->getVariables();
        $this->assertFalse($variables['success']);
    }

    public function testErrorResponseContainsMessage(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'errorResponse', [400, 'Custom error message']);

        $variables = $result->getVariables();
        $this->assertEquals('Custom error message', $variables['message']);
    }

    public function testErrorResponseSetsStatusCode(): void
    {
        $this->callProtectedMethod($this->controller, 'errorResponse', [404, 'Not found']);

        $this->assertEquals(404, $this->controller->getResponse()->getStatusCode());
    }

    public function testErrorResponse401(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'errorResponse', [401, 'Unauthorized']);

        $this->assertEquals(401, $this->controller->getResponse()->getStatusCode());
        $this->assertEquals('Unauthorized', $result->getVariables()['message']);
    }

    public function testErrorResponse403(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'errorResponse', [403, 'Forbidden']);

        $this->assertEquals(403, $this->controller->getResponse()->getStatusCode());
        $this->assertEquals('Forbidden', $result->getVariables()['message']);
    }

    public function testErrorResponse500(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'errorResponse', [500, 'Server error']);

        $this->assertEquals(500, $this->controller->getResponse()->getStatusCode());
        $this->assertEquals('Server error', $result->getVariables()['message']);
    }

    // =========================================================================
    // getMediaOrFail() tests
    // =========================================================================

    public function testGetMediaOrFailReturnsNullOnException(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'getMediaOrFail', [999]);

        $this->assertNull($result);
    }

    // =========================================================================
    // saveAction() tests - basic validation
    // =========================================================================

    public function testSaveActionRequiresPostMethod(): void
    {
        // Create mock request that is not POST
        $request = new class {
            public function isPost(): bool { return false; }
        };

        $this->controller->setRequest($request);
        $result = $this->controller->saveAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertEquals(405, $this->controller->getResponse()->getStatusCode());
        $this->assertEquals('Method not allowed', $result->getVariables()['message']);
    }

    public function testSaveActionRequiresAuthentication(): void
    {
        $request = new class {
            public function isPost(): bool { return true; }
        };

        $this->controller->setRequest($request);
        $this->controller->setIdentity(null);

        $result = $this->controller->saveAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertEquals(401, $this->controller->getResponse()->getStatusCode());
        $this->assertEquals('Unauthorized', $result->getVariables()['message']);
    }

    public function testSaveActionRequiresMediaId(): void
    {
        $request = new class {
            public function isPost(): bool { return true; }
            public function getPost($key = null) { return null; }
            public function getHeaders() {
                return new class {
                    public function get($name) { return null; }
                };
            }
        };

        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $this->controller->setRequest($request);
        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams([]);

        $result = $this->controller->saveAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertEquals(400, $this->controller->getResponse()->getStatusCode());
        $this->assertEquals('Media ID required', $result->getVariables()['message']);
    }

    // =========================================================================
    // getDataAction() tests
    // =========================================================================

    public function testGetDataActionRequiresMediaId(): void
    {
        $this->controller->setRouteParams([]);

        $result = $this->controller->getDataAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertEquals(400, $this->controller->getResponse()->getStatusCode());
        $this->assertEquals('Media ID required', $result->getVariables()['message']);
    }

    public function testGetDataActionReturns404ForNonExistentMedia(): void
    {
        $this->controller->setRouteParams(['id' => '999']);

        $result = $this->controller->getDataAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertEquals(404, $this->controller->getResponse()->getStatusCode());
        $this->assertEquals('Media not found', $result->getVariables()['message']);
    }

    // =========================================================================
    // HTTP Status Code tests
    // =========================================================================

    /**
     * @dataProvider httpStatusCodeProvider
     */
    public function testErrorResponseStatusCodes(int $code, string $expectedMessage): void
    {
        $result = $this->callProtectedMethod($this->controller, 'errorResponse', [$code, $expectedMessage]);

        $this->assertEquals($code, $this->controller->getResponse()->getStatusCode());
        $this->assertFalse($result->getVariables()['success']);
    }

    public function httpStatusCodeProvider(): array
    {
        return [
            'Bad Request' => [400, 'Bad Request'],
            'Unauthorized' => [401, 'Unauthorized'],
            'Forbidden' => [403, 'Forbidden'],
            'Not Found' => [404, 'Not Found'],
            'Method Not Allowed' => [405, 'Method Not Allowed'],
            'Internal Server Error' => [500, 'Internal Server Error'],
        ];
    }

    // =========================================================================
    // saveAction() - media not found tests
    // =========================================================================

    public function testSaveActionReturns404WhenMediaNotFound(): void
    {
        $request = new class {
            public function isPost(): bool { return true; }
            public function getPost($key = null) { return null; }
            public function getHeaders() {
                return new class {
                    public function get($name) { return null; }
                };
            }
        };

        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $this->controller->setRequest($request);
        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '999']);

        $result = $this->controller->saveAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertEquals(404, $this->controller->getResponse()->getStatusCode());
        $this->assertEquals('Media not found', $result->getVariables()['message']);
    }

    // =========================================================================
    // Controller class tests
    // =========================================================================

    public function testControllerExtendsAbstractActionController(): void
    {
        $this->assertInstanceOf(
            \Laminas\Mvc\Controller\AbstractActionController::class,
            $this->controller
        );
    }

    public function testControllerHasSaveAction(): void
    {
        $this->assertTrue(method_exists($this->controller, 'saveAction'));
    }

    public function testControllerHasGetDataAction(): void
    {
        $this->assertTrue(method_exists($this->controller, 'getDataAction'));
    }

    // =========================================================================
    // Response tests
    // =========================================================================

    public function testGetResponseReturnsResponse(): void
    {
        $response = $this->controller->getResponse();

        $this->assertInstanceOf(\Laminas\Http\Response::class, $response);
    }

    public function testSetAndGetResponse(): void
    {
        $response = new \Laminas\Http\Response();
        $response->setStatusCode(201);

        $this->controller->setResponse($response);

        $this->assertEquals(201, $this->controller->getResponse()->getStatusCode());
    }

    // =========================================================================
    // JsonModel structure tests
    // =========================================================================

    public function testErrorResponseStructure(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'errorResponse', [400, 'Test']);

        $variables = $result->getVariables();
        $this->assertArrayHasKey('success', $variables);
        $this->assertArrayHasKey('message', $variables);
        $this->assertCount(2, $variables);
    }

    public function testSuccessResponseShouldHaveSuccessTrue(): void
    {
        // Test that a successful response should have success: true
        $jsonModel = new JsonModel(['success' => true, 'data' => 'test']);
        $this->assertTrue($jsonModel->getVariable('success'));
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testErrorResponseWithEmptyMessage(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'errorResponse', [400, '']);

        $this->assertEquals('', $result->getVariables()['message']);
    }

    public function testErrorResponseWithLongMessage(): void
    {
        $longMessage = str_repeat('Error ', 100);
        $result = $this->callProtectedMethod($this->controller, 'errorResponse', [400, $longMessage]);

        $this->assertEquals($longMessage, $result->getVariables()['message']);
    }

    public function testMultipleErrorResponses(): void
    {
        $this->callProtectedMethod($this->controller, 'errorResponse', [400, 'First error']);
        $this->callProtectedMethod($this->controller, 'errorResponse', [500, 'Second error']);

        // Last status code should be set
        $this->assertEquals(500, $this->controller->getResponse()->getStatusCode());
    }

    // =========================================================================
    // saveAction() - permission denied tests
    // =========================================================================

    public function testSaveActionReturns403WhenUserNotAllowed(): void
    {
        $request = new class {
            public function isPost(): bool { return true; }
            public function getPost($key = null) { return null; }
            public function getHeaders() {
                return new class {
                    public function get($name) { return null; }
                };
            }
            public function getFiles() { return []; }
        };

        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        // Create a media object
        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/test.elpx',
            'Test ELP',
            'test.elpx',
            123
        );

        $this->controller->setRequest($request);
        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);
        $this->controller->setUserAllowed(false);

        $result = $this->controller->saveAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertEquals(403, $this->controller->getResponse()->getStatusCode());
        $this->assertEquals('Forbidden', $result->getVariables()['message']);
    }

    // =========================================================================
    // saveAction() - file upload validation tests
    // =========================================================================

    public function testSaveActionReturns400WhenNoFileUploaded(): void
    {
        $request = new class {
            public function isPost(): bool { return true; }
            public function getPost($key = null) { return null; }
            public function getHeaders() {
                return new class {
                    public function get($name) { return null; }
                };
            }
            public function getFiles() { return []; }
        };

        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/test.elpx',
            'Test ELP',
            'test.elpx',
            123
        );

        $this->controller->setRequest($request);
        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);
        $this->controller->setUserAllowed(true);

        $result = $this->controller->saveAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertEquals(400, $this->controller->getResponse()->getStatusCode());
        $this->assertEquals('No file uploaded', $result->getVariables()['message']);
    }

    public function testSaveActionReturns400WhenUploadError(): void
    {
        $request = new class {
            public function isPost(): bool { return true; }
            public function getPost($key = null) { return null; }
            public function getHeaders() {
                return new class {
                    public function get($name) { return null; }
                };
            }
            public function getFiles() {
                return ['file' => ['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '/tmp/test']];
            }
        };

        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/test.elpx',
            'Test ELP',
            'test.elpx',
            123
        );

        $this->controller->setRequest($request);
        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);
        $this->controller->setUserAllowed(true);

        $result = $this->controller->saveAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertEquals(400, $this->controller->getResponse()->getStatusCode());
        $this->assertStringContainsString('Upload failed', $result->getVariables()['message']);
    }

    // =========================================================================
    // saveAction() - successful save tests
    // =========================================================================

    public function testSaveActionSuccessWithPreview(): void
    {
        $request = new class {
            public function isPost(): bool { return true; }
            public function getPost($key = null) { return null; }
            public function getHeaders() {
                return new class {
                    public function get($name) { return null; }
                };
            }
            public function getFiles() {
                return ['file' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/test.elpx']];
            }
            public function getUri() { return new \Laminas\Uri\Http(); }
            public function getBasePath(): string { return ''; }
        };

        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/test.elpx',
            'Test ELP',
            'test.elpx',
            123
        );

        $this->elpService->method('replaceFile')
            ->willReturn(['hasPreview' => true, 'hash' => 'abc123def456789012345678901234567890abcd']);

        $this->controller->setRequest($request);
        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);
        $this->controller->setUserAllowed(true);

        $result = $this->controller->saveAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertTrue($result->getVariables()['success']);
        $this->assertEquals('File saved successfully', $result->getVariables()['message']);
        $this->assertEquals(123, $result->getVariables()['media_id']);
        $this->assertNotNull($result->getVariables()['contentPath']);
        $this->assertStringContainsString('/exelearning/content/', $result->getVariables()['contentPath']);
    }

    public function testSaveActionSuccessWithoutPreview(): void
    {
        $request = new class {
            public function isPost(): bool { return true; }
            public function getPost($key = null) { return null; }
            public function getHeaders() {
                return new class {
                    public function get($name) { return null; }
                };
            }
            public function getFiles() {
                return ['file' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/test.elpx']];
            }
        };

        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/test.elpx',
            'Test ELP',
            'test.elpx',
            123
        );

        $this->elpService->method('replaceFile')
            ->willReturn(['hasPreview' => false, 'hash' => null]);

        $this->controller->setRequest($request);
        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);
        $this->controller->setUserAllowed(true);

        $result = $this->controller->saveAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertTrue($result->getVariables()['success']);
        $this->assertNull($result->getVariables()['contentPath']);
    }

    public function testSaveActionReturns500OnException(): void
    {
        $request = new class {
            public function isPost(): bool { return true; }
            public function getPost($key = null) { return null; }
            public function getHeaders() {
                return new class {
                    public function get($name) { return null; }
                };
            }
            public function getFiles() {
                return ['file' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/test.elpx']];
            }
        };

        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/test.elpx',
            'Test ELP',
            'test.elpx',
            123
        );

        $this->elpService->method('replaceFile')
            ->willThrowException(new \Exception('Disk full'));

        $this->controller->setRequest($request);
        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);
        $this->controller->setUserAllowed(true);

        $result = $this->controller->saveAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertEquals(500, $this->controller->getResponse()->getStatusCode());
        $this->assertStringContainsString('Save failed', $result->getVariables()['message']);
        $this->assertStringContainsString('Disk full', $result->getVariables()['message']);
    }

    // =========================================================================
    // getDataAction() - successful response tests
    // =========================================================================

    public function testGetDataActionSuccessWithPreview(): void
    {
        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/test.elpx',
            'Test ELP',
            'test.elpx',
            123,
            ['exelearning' => ['hash' => 'abc123def456789012345678901234567890abcd', 'hasPreview' => true]]
        );

        $this->elpService->method('getMediaHash')
            ->willReturn('abc123def456789012345678901234567890abcd');
        $this->elpService->method('hasPreview')
            ->willReturn(true);

        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);

        $result = $this->controller->getDataAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertTrue($result->getVariables()['success']);
        $this->assertEquals(123, $result->getVariables()['id']);
        $this->assertEquals('http://example.com/files/original/test.elpx', $result->getVariables()['url']);
        $this->assertEquals('Test ELP', $result->getVariables()['title']);
        $this->assertEquals('test.elpx', $result->getVariables()['filename']);
        $this->assertTrue($result->getVariables()['hasPreview']);
        $this->assertNotNull($result->getVariables()['contentPath']);
        $this->assertStringContainsString('/exelearning/content/', $result->getVariables()['contentPath']);
    }

    public function testGetDataActionSuccessWithoutPreview(): void
    {
        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/test.elpx',
            'Test ELP',
            'test.elpx',
            123
        );

        $this->elpService->method('getMediaHash')
            ->willReturn(null);
        $this->elpService->method('hasPreview')
            ->willReturn(false);

        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);

        $result = $this->controller->getDataAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertTrue($result->getVariables()['success']);
        $this->assertFalse($result->getVariables()['hasPreview']);
        $this->assertNull($result->getVariables()['contentPath']);
    }

    public function testGetDataActionWithEmptyHash(): void
    {
        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/test.elpx',
            'Test ELP',
            'test.elpx',
            123
        );

        $this->elpService->method('getMediaHash')
            ->willReturn('');
        $this->elpService->method('hasPreview')
            ->willReturn(true);

        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);

        $result = $this->controller->getDataAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        // Empty hash is falsy, so contentPath should be null
        $this->assertNull($result->getVariables()['contentPath']);
    }

    // =========================================================================
    // getMediaOrFail() tests
    // =========================================================================

    public function testGetMediaOrFailReturnsMediaWhenExists(): void
    {
        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/test.elpx',
            'Test ELP',
            'test.elpx',
            456
        );

        $this->controller->addMedia(456, $media);

        $result = $this->callProtectedMethod($this->controller, 'getMediaOrFail', [456]);

        $this->assertSame($media, $result);
    }

    public function testGetMediaOrFailReturnsNullForNonExistent(): void
    {
        // Don't add any media
        $result = $this->callProtectedMethod($this->controller, 'getMediaOrFail', [999]);

        $this->assertNull($result);
    }

    // =========================================================================
    // setTeacherModeAction() tests
    // =========================================================================

    public function testSetTeacherModeActionRequiresPostMethod(): void
    {
        $request = new class {
            public function isPost(): bool { return false; }
        };

        $this->controller->setRequest($request);
        $result = $this->controller->setTeacherModeAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertEquals(405, $this->controller->getResponse()->getStatusCode());
        $this->assertEquals('Method not allowed', $result->getVariables()['message']);
    }

    public function testSetTeacherModeActionRequiresAuthentication(): void
    {
        $request = new class {
            public function isPost(): bool { return true; }
        };

        $this->controller->setRequest($request);
        $this->controller->setIdentity(null);

        $result = $this->controller->setTeacherModeAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertEquals(401, $this->controller->getResponse()->getStatusCode());
        $this->assertEquals('Unauthorized', $result->getVariables()['message']);
    }

    public function testSetTeacherModeActionRequiresMediaId(): void
    {
        $request = new class {
            public function isPost(): bool { return true; }
            public function getPost($key = null, $default = null) { return $default; }
        };

        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $this->controller->setRequest($request);
        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams([]);

        $result = $this->controller->setTeacherModeAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertEquals(400, $this->controller->getResponse()->getStatusCode());
        $this->assertEquals('Media ID required', $result->getVariables()['message']);
    }

    public function testSetTeacherModeActionReturns404WhenMediaNotFound(): void
    {
        $request = new class {
            public function isPost(): bool { return true; }
            public function getPost($key = null, $default = null) { return $default; }
        };

        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $this->controller->setRequest($request);
        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '999']);

        $result = $this->controller->setTeacherModeAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertEquals(404, $this->controller->getResponse()->getStatusCode());
        $this->assertEquals('Media not found', $result->getVariables()['message']);
    }

    public function testSetTeacherModeActionReturns403WhenUserNotAllowed(): void
    {
        $request = new class {
            public function isPost(): bool { return true; }
            public function getPost($key = null, $default = null) { return $default; }
        };

        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/test.elpx',
            'Test ELP',
            'test.elpx',
            123
        );

        $this->controller->setRequest($request);
        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);
        $this->controller->setUserAllowed(false);

        $result = $this->controller->setTeacherModeAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertEquals(403, $this->controller->getResponse()->getStatusCode());
        $this->assertEquals('Forbidden', $result->getVariables()['message']);
    }

    public function testSetTeacherModeActionSuccessVisible(): void
    {
        $request = new class {
            public function isPost(): bool { return true; }
            public function getPost($key = null, $default = null) {
                if ($key === 'teacher_mode_visible') {
                    return '1';
                }
                return $default;
            }
        };

        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/test.elpx',
            'Test ELP',
            'test.elpx',
            123
        );

        $this->elpService->expects($this->once())
            ->method('setTeacherModeVisible')
            ->with($media, true);

        $this->controller->setRequest($request);
        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);
        $this->controller->setUserAllowed(true);

        $result = $this->controller->setTeacherModeAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertTrue($result->getVariables()['success']);
        $this->assertEquals(123, $result->getVariables()['media_id']);
        $this->assertTrue($result->getVariables()['teacherModeVisible']);
    }

    public function testSetTeacherModeActionSuccessHidden(): void
    {
        $request = new class {
            public function isPost(): bool { return true; }
            public function getPost($key = null, $default = null) {
                if ($key === 'teacher_mode_visible') {
                    return '0';
                }
                return $default;
            }
        };

        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/test.elpx',
            'Test ELP',
            'test.elpx',
            123
        );

        $this->elpService->expects($this->once())
            ->method('setTeacherModeVisible')
            ->with($media, false);

        $this->controller->setRequest($request);
        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);
        $this->controller->setUserAllowed(true);

        $result = $this->controller->setTeacherModeAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertTrue($result->getVariables()['success']);
        $this->assertFalse($result->getVariables()['teacherModeVisible']);
    }

    public function testSetTeacherModeActionReturns500OnException(): void
    {
        $request = new class {
            public function isPost(): bool { return true; }
            public function getPost($key = null, $default = null) {
                if ($key === 'teacher_mode_visible') {
                    return '1';
                }
                return $default;
            }
        };

        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/test.elpx',
            'Test ELP',
            'test.elpx',
            123
        );

        $this->elpService->method('setTeacherModeVisible')
            ->willThrowException(new \Exception('Database error'));

        $this->controller->setRequest($request);
        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);
        $this->controller->setUserAllowed(true);

        $result = $this->controller->setTeacherModeAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertEquals(500, $this->controller->getResponse()->getStatusCode());
        $this->assertStringContainsString('Update failed', $result->getVariables()['message']);
    }

    public function testSetTeacherModeActionWithFalseString(): void
    {
        $request = new class {
            public function isPost(): bool { return true; }
            public function getPost($key = null, $default = null) {
                if ($key === 'teacher_mode_visible') {
                    return 'false';
                }
                return $default;
            }
        };

        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/test.elpx',
            'Test ELP',
            'test.elpx',
            123
        );

        $this->elpService->expects($this->once())
            ->method('setTeacherModeVisible')
            ->with($media, false);

        $this->controller->setRequest($request);
        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);
        $this->controller->setUserAllowed(true);

        $result = $this->controller->setTeacherModeAction();

        $this->assertTrue($result->getVariables()['success']);
        $this->assertFalse($result->getVariables()['teacherModeVisible']);
    }

    // =========================================================================
    // installEditorAction() tests
    // =========================================================================

    public function testInstallEditorActionRequiresPostMethod(): void
    {
        $request = new class {
            public function isPost(): bool { return false; }
        };

        $this->controller->setRequest($request);
        $result = $this->controller->installEditorAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertEquals(405, $this->controller->getResponse()->getStatusCode());
        $this->assertEquals('Method not allowed', $result->getVariables()['message']);
    }

    public function testInstallEditorActionRequiresAuthentication(): void
    {
        $request = new class {
            public function isPost(): bool { return true; }
        };

        $this->controller->setRequest($request);
        $this->controller->setIdentity(null);

        $result = $this->controller->installEditorAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertEquals(401, $this->controller->getResponse()->getStatusCode());
        $this->assertEquals('Unauthorized', $result->getVariables()['message']);
    }

    public function testInstallEditorActionRequiresPermission(): void
    {
        $request = new class {
            public function isPost(): bool { return true; }
            public function getPost($key = null) { return null; }
            public function getHeaders() {
                return new class {
                    public function get($name) { return null; }
                };
            }
            public function getFiles() { return []; }
        };

        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $this->controller->setRequest($request);
        $this->controller->setIdentity($identity);
        $this->controller->setUserAllowed(false);

        $result = $this->controller->installEditorAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertEquals(403, $this->controller->getResponse()->getStatusCode());
        $this->assertEquals('Forbidden', $result->getVariables()['message']);
    }

    public function testInstallEditorActionWithUploadedZip(): void
    {
        // Create a valid ZIP with editor contents
        $tmpZip = tempnam(sys_get_temp_dir(), 'test-editor-');
        $zip = new \ZipArchive();
        $zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('index.html', '<html><body>Editor</body></html>');
        $zip->addEmptyDir('app');
        $zip->close();

        $request = new class($tmpZip) {
            private string $tmpZip;
            public function __construct(string $tmpZip)
            {
                $this->tmpZip = $tmpZip;
            }
            public function isPost(): bool { return true; }
            public function getPost($key = null, $default = null)
            {
                if ($key === 'version') {
                    return '4.0.0-test';
                }
                return $default;
            }
            public function getHeaders() {
                return new class {
                    public function get($name) { return null; }
                };
            }
            public function getFiles() {
                return ['file' => [
                    'error' => UPLOAD_ERR_OK,
                    'tmp_name' => $this->tmpZip,
                ]];
            }
        };

        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Admin'; }
        };

        $this->controller->setRequest($request);
        $this->controller->setIdentity($identity);
        $this->controller->setUserAllowed(true);

        $result = $this->controller->installEditorAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $vars = $result->getVariables();
        $this->assertTrue($vars['success']);
        $this->assertEquals('4.0.0-test', $vars['version']);
        $this->assertStringContainsString('4.0.0-test', $vars['message']);

        // Clean up installed files
        $editorPath = \ExeLearning\Service\StaticEditorInstaller::getEditorPath();
        if (is_dir($editorPath)) {
            $installer = new \ExeLearning\Service\StaticEditorInstaller();
            $installer->cleanupDirectory($editorPath);
        }
        @unlink($tmpZip);
    }

    public function testInstallEditorActionWithInvalidZip(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test-bad-');
        file_put_contents($tmpFile, 'This is not a ZIP');

        $request = new class($tmpFile) {
            private string $tmpFile;
            public function __construct(string $tmpFile)
            {
                $this->tmpFile = $tmpFile;
            }
            public function isPost(): bool { return true; }
            public function getPost($key = null, $default = null)
            {
                return $default;
            }
            public function getHeaders() {
                return new class {
                    public function get($name) { return null; }
                };
            }
            public function getFiles() {
                return ['file' => [
                    'error' => UPLOAD_ERR_OK,
                    'tmp_name' => $this->tmpFile,
                ]];
            }
        };

        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Admin'; }
        };

        $this->controller->setRequest($request);
        $this->controller->setIdentity($identity);
        $this->controller->setUserAllowed(true);

        $result = $this->controller->installEditorAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertEquals(500, $this->controller->getResponse()->getStatusCode());
        $this->assertStringContainsString('not a valid ZIP', $result->getVariables()['message']);

        @unlink($tmpFile);
    }

    public function testInstallEditorActionWithZipMissingAssetDirs(): void
    {
        // Upload a valid ZIP but missing required asset directories
        $tmpZip = tempnam(sys_get_temp_dir(), 'test-editor-');
        $zip = new \ZipArchive();
        $zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('index.html', '<html></html>');
        $zip->close();

        $request = new class($tmpZip) {
            private string $tmpZip;
            public function __construct(string $tmpZip)
            {
                $this->tmpZip = $tmpZip;
            }
            public function isPost(): bool { return true; }
            public function getPost($key = null, $default = null) { return $default; }
            public function getHeaders() {
                return new class {
                    public function get($name) { return null; }
                };
            }
            public function getFiles() {
                return ['file' => [
                    'error' => UPLOAD_ERR_OK,
                    'tmp_name' => $this->tmpZip,
                ]];
            }
        };

        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Admin'; }
        };

        $this->controller->setRequest($request);
        $this->controller->setIdentity($identity);
        $this->controller->setUserAllowed(true);

        $result = $this->controller->installEditorAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertEquals(500, $this->controller->getResponse()->getStatusCode());
        $this->assertStringContainsString('missing expected asset directories', $result->getVariables()['message']);

        @unlink($tmpZip);
    }

    public function testInstallEditorActionHasMethod(): void
    {
        $this->assertTrue(method_exists($this->controller, 'installEditorAction'));
    }

    public function testInstallEditorActionWithInvalidDownloadUrl(): void
    {
        $request = new class {
            public function isPost(): bool { return true; }
            public function getPost($key = null, $default = null)
            {
                $data = [
                    'download_url' => 'https://invalid.example.com/nonexistent.zip',
                    'version' => '1.0.0',
                ];
                if ($key === null) return $default;
                return $data[$key] ?? $default;
            }
            public function getHeaders() {
                return new class {
                    public function get($name) { return null; }
                };
            }
            public function getFiles() { return []; }
        };

        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Admin'; }
        };

        $this->controller->setRequest($request);
        $this->controller->setIdentity($identity);
        $this->controller->setUserAllowed(true);

        $result = $this->controller->installEditorAction();

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertEquals(500, $this->controller->getResponse()->getStatusCode());
        $this->assertFalse($result->getVariables()['success']);
    }

    public function testSetTeacherModeActionWithNoString(): void
    {
        $request = new class {
            public function isPost(): bool { return true; }
            public function getPost($key = null, $default = null) {
                if ($key === 'teacher_mode_visible') {
                    return 'no';
                }
                return $default;
            }
        };

        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/test.elpx',
            'Test ELP',
            'test.elpx',
            123
        );

        $this->elpService->expects($this->once())
            ->method('setTeacherModeVisible')
            ->with($media, false);

        $this->controller->setRequest($request);
        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);
        $this->controller->setUserAllowed(true);

        $result = $this->controller->setTeacherModeAction();

        $this->assertTrue($result->getVariables()['success']);
        $this->assertFalse($result->getVariables()['teacherModeVisible']);
    }

    // =========================================================================
    // buildContentUrl() tests
    // =========================================================================

    public function testBuildContentUrlIncludesNonStandardPort(): void
    {
        $uri = new class extends \Laminas\Uri\Http {
            public function getPort(): ?int { return 8080; }
        };
        $request = new class($uri) extends \Laminas\Http\Request {
            private $customUri;
            public function __construct($uri) { $this->customUri = $uri; }
            public function getUri(): \Laminas\Uri\Http { return $this->customUri; }
        };

        $this->controller->setRequest($request);

        $url = $this->callProtectedMethod($this->controller, 'buildContentUrl', ['abc123def456789012345678901234567890abcd']);

        $this->assertStringContainsString(':8080', $url);
        $this->assertStringContainsString('/exelearning/content/abc123def456789012345678901234567890abcd/index.html', $url);
    }

    public function testBuildContentUrlStripsPlaygroundPrefixFromUriPath(): void
    {
        $uri = new class extends \Laminas\Uri\Http {
            public function getPath(): string { return '/omeka-s-playground/playground/abc123/php83/admin/media/3'; }
        };
        $request = new class($uri) extends \Laminas\Http\Request {
            private $customUri;
            public function __construct($uri) { $this->customUri = $uri; }
            public function getUri(): \Laminas\Uri\Http { return $this->customUri; }
        };

        $this->controller->setRequest($request);

        $url = $this->callProtectedMethod($this->controller, 'buildContentUrl', ['abc123def456789012345678901234567890abcd']);

        $this->assertStringContainsString('/omeka-s-playground/playground/abc123/php83/exelearning/content/', $url);
        $this->assertStringNotContainsString('/admin/', $url);
    }

    public function testExtractBasePathWithAdminRoute(): void
    {
        $basePath = $this->callProtectedMethod($this->controller, 'extractBasePath', ['/playground/uuid/php83/admin/media/3']);
        $this->assertSame('/playground/uuid/php83', $basePath);
    }

    public function testExtractBasePathWithApiRoute(): void
    {
        $basePath = $this->callProtectedMethod($this->controller, 'extractBasePath', ['/playground/uuid/php83/api/exelearning/save/1']);
        $this->assertSame('/playground/uuid/php83', $basePath);
    }

    public function testExtractBasePathWithNoKnownMarker(): void
    {
        $basePath = $this->callProtectedMethod($this->controller, 'extractBasePath', ['/some/unknown/path']);
        $this->assertSame('', $basePath);
    }
}
