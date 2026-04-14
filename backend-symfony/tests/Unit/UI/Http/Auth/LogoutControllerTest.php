<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Http\Auth;

use App\Application\Auth\AuthServiceInterface;
use App\UI\Http\Auth\LogoutController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class LogoutControllerTest extends TestCase
{
    private AuthServiceInterface&MockObject $authService;
    private ValidatorInterface&MockObject $validator;
    private SerializerInterface&MockObject $serializer;
    private LogoutController $controller;

    protected function setUp(): void
    {
        $this->authService = $this->createMock(AuthServiceInterface::class);
        $this->validator = $this->createMock(ValidatorInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);

        $this->controller = new LogoutController(
            $this->authService,
            $this->validator,
            $this->serializer,
        );
    }

    public function test_returns_400_on_invalid_json(): void
    {
        $this->serializer->method('deserialize')
            ->willThrowException(new NotEncodableValueException('Invalid JSON'));

        $request = Request::create('/api/v1/auth/logout', 'POST', [], [], [], [], 'invalid');
        $response = $this->controller->__invoke($request);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Invalid JSON', $data['message']);
    }

    public function test_returns_422_on_validation_errors(): void
    {
        $dto = new \App\Application\Auth\Dto\RefreshRequestDto('');
        $this->serializer->method('deserialize')->willReturn($dto);

        $violation = $this->createMock(\Symfony\Component\Validator\ConstraintViolationInterface::class);
        $violation->method('__toString')->willReturn('Refresh token required');
        $violations = new ConstraintViolationList([$violation]);
        $this->validator->method('validate')->willReturn($violations);

        $request = Request::create('/api/v1/auth/logout', 'POST', [], [], [], [], '{"refresh_token":""}');
        $response = $this->controller->__invoke($request);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_returns_204_on_successful_logout(): void
    {
        $dto = new \App\Application\Auth\Dto\RefreshRequestDto('valid-token');
        $this->serializer->method('deserialize')->willReturn($dto);
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());
        $this->authService->expects($this->once())->method('logout')->with('valid-token');

        $request = Request::create('/api/v1/auth/logout', 'POST', [], [], [], [], '{"refresh_token":"valid-token"}');
        $response = $this->controller->__invoke($request);

        $this->assertSame(204, $response->getStatusCode());
    }

    public function test_returns_401_on_invalid_token(): void
    {
        $dto = new \App\Application\Auth\Dto\RefreshRequestDto('expired-token');
        $this->serializer->method('deserialize')->willReturn($dto);
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());
        $this->authService->method('logout')
            ->willThrowException(new \RuntimeException('Invalid refresh token'));

        $request = Request::create('/api/v1/auth/logout', 'POST', [], [], [], [], '{"refresh_token":"expired-token"}');
        $response = $this->controller->__invoke($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_returns_401_on_already_used_token(): void
    {
        $dto = new \App\Application\Auth\Dto\RefreshRequestDto('used-token');
        $this->serializer->method('deserialize')->willReturn($dto);
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());
        $this->authService->method('logout')
            ->willThrowException(new \RuntimeException('Token already revoked'));

        $request = Request::create('/api/v1/auth/logout', 'POST', [], [], [], [], '{"refresh_token":"used-token"}');
        $response = $this->controller->__invoke($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_returns_400_on_other_errors(): void
    {
        $dto = new \App\Application\Auth\Dto\RefreshRequestDto('bad-token');
        $this->serializer->method('deserialize')->willReturn($dto);
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());
        $this->authService->method('logout')
            ->willThrowException(new \RuntimeException('Database error'));

        $request = Request::create('/api/v1/auth/logout', 'POST', [], [], [], [], '{"refresh_token":"bad-token"}');
        $response = $this->controller->__invoke($request);

        $this->assertSame(400, $response->getStatusCode());
    }
}
