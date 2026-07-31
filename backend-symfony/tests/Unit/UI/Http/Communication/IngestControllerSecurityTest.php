<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Http\Communication;

use App\Application\Communication\IngestHandler;
use App\UI\Http\Communication\IngestController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Verifies that IngestController 500 responses do NOT leak exception details.
 */
class IngestControllerSecurityTest extends TestCase
{
    private IngestHandler&MockObject $handler;
    private ValidatorInterface&MockObject $validator;
    private SerializerInterface&MockObject $serializer;

    protected function setUp(): void
    {
        $this->handler = $this->createMock(IngestHandler::class);
        $this->validator = $this->createMock(ValidatorInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
    }

    public function test_500_response_does_not_leak_exception_message(): void
    {
        $sensitiveMessage = 'SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "secrets" does not exist';

        // Serializer returns a DTO, validator passes, handler throws
        $dto = new \App\Application\Communication\IngestRawRequestDto();
        $dto->raw_source = 'From: x@x.com';
        $dto->account_id = 'test-account';

        $this->serializer->method('deserialize')->willReturn($dto);
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());
        $this->handler->method('ingest')->willThrowException(new \Exception($sensitiveMessage));

        $controller = new IngestController(
            $this->handler,
            $this->validator,
            $this->serializer,
            new NullLogger(),
        );

        $request = Request::create('/api/v1/communication/ingest/raw', 'POST', [], [], [], [], json_encode([
            'raw_rfc822' => 'From: x@x.com',
            'account_id' => 'test-account',
        ]));
        $request->attributes->set('trace_id', 'abc-123');

        $response = $controller->__invoke($request);

        $this->assertSame(500, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        // Must NOT contain the sensitive SQL error
        $this->assertSame('Internal server error', $data['error']);
        $this->assertStringNotContainsString('SQLSTATE', $data['error']);
        $this->assertStringNotContainsString($sensitiveMessage, $response->getContent());

        // Must contain trace_id for log correlation
        $this->assertSame('abc-123', $data['trace_id']);
    }
}
