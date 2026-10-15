<?php

namespace App\Tests\Dto;

use App\Dto\CreateTaskRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CreateTaskRequestTest extends TestCase
{
    public function testValidRequestPassesValidation(): void
    {
        self::assertCount(0, $this->validator()->validate($this->validRequest()));
    }

    public function testBlankStatementFailsValidation(): void
    {
        $request = $this->validRequest();
        $request->statement = '';

        $violations = $this->validator()->validate($request);

        self::assertCount(1, $violations);
        self::assertSame('statement', $violations->get(0)->getPropertyPath());
    }

    public function testBlankPasswordFailsValidation(): void
    {
        $request = $this->validRequest();
        $request->password = '';

        $violations = $this->validator()->validate($request);

        // An empty string violates both NotBlank and Length(min: 8).
        self::assertCount(2, $violations);
        self::assertSame('password', $violations->get(0)->getPropertyPath());
    }

    public function testShortPasswordFailsValidation(): void
    {
        $request = $this->validRequest();
        $request->password = 'short';

        $violations = $this->validator()->validate($request);

        self::assertCount(1, $violations);
        self::assertSame('password', $violations->get(0)->getPropertyPath());
    }

    public function testInvalidLocationFailsValidation(): void
    {
        $request = $this->validRequest();
        $request->setLocation('not-a-location');

        $violations = $this->validator()->validate($request);

        self::assertCount(1, $violations);
        self::assertSame('location', $violations->get(0)->getPropertyPath());
    }

    public function testNonPositivePriceSunFailsValidation(): void
    {
        $request = $this->validRequest();
        $request->price = "0";

        $violations = $this->validator()->validate($request);

        // "0" violates both Positive and GreaterThanOrEqual(10).
        self::assertCount(2, $violations);
        self::assertSame('price', $violations->get(0)->getPropertyPath());
    }

    public function testPriceBelowMinimumFailsValidation(): void
    {
        $request = $this->validRequest();
        $request->price = "5";

        $violations = $this->validator()->validate($request);

        self::assertCount(1, $violations);
        self::assertSame('price', $violations->get(0)->getPropertyPath());
    }

    public function testValidTronRefundAddressPassesValidation(): void
    {
        $request = $this->validRequest();
        $request->refundAddress = 'TQn9Y2khEsLJW1ChVWFMSMeRDow5KcbLSE';
        self::assertCount(0, $this->validator()->validate($request));
    }

    public function testInvalidRefundAddressFailsValidation(): void
    {
        $request = $this->validRequest();
        $request->refundAddress = 'not-a-tron-address';

        $violations = $this->validator()->validate($request);

        self::assertCount(1, $violations);
        self::assertSame('refundAddress', $violations->get(0)->getPropertyPath());
    }

    public function testNullRefundAddressPassesValidation(): void
    {
        $request = $this->validRequest();
        $request->refundAddress = null;

        self::assertCount(0, $this->validator()->validate($request));
    }

    public function testValidWebhookPassesValidation(): void
    {
        $request = $this->validRequest();
        $request->webhook = 'https://example.com/hook';

        self::assertCount(0, $this->validator()->validate($request));
    }

    public function testInvalidWebhookFailsValidation(): void
    {
        $request = $this->validRequest();
        $request->webhook = 'not-a-url';

        $violations = $this->validator()->validate($request);

        self::assertCount(1, $violations);
        self::assertSame('webhook', $violations->get(0)->getPropertyPath());
    }

    public function testNullWebhookPassesValidation(): void
    {
        $request = $this->validRequest();
        $request->webhook = null;

        self::assertCount(0, $this->validator()->validate($request));
    }

    private function validRequest(): CreateTaskRequest
    {
        $request = new CreateTaskRequest();
        $request->statement = 'move the box';
        $request->password = 'secret123';

        return $request;
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
