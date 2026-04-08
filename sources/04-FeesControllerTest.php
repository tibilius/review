<?php

declare(strict_types=1);

namespace Tests\Functional\Api\Fees;

use App\Repository\FeesRepository;
use GuzzleHttp\Psr7\Response;
use Tests\Functional\Api\ApiTestCase;
use Tests\Support\GuzzleHttpMockHandler;

class FeesControllerTest extends ApiTestCase
{
    /**
     * Dump test to understand response structure
     */
    public function testCreateOrUpdateFeesDump(): void
    {
        $this->login($this->getTestUser(['ROLE_FEES_EDIT']));
        $this->request('POST', $this->route('api.v1.fees.create-or-update-fees'), [
            'aliasName' => 'Withdrawal Standard',
            'merchantId' => '11111111-1111-1111-1111-111111111111',
            'brokerUserId' => 100001,
            'brokerFee' => '0.1',
            'platformFee' => '0.2',
            'revshareFee' => '0.05',
            'revshareAccountName' => 'partner',
        ]);

        $response = $this->client->getResponse();

        $this->assertTrue($response->isSuccessful(), 'Response should be successful.');
    }

    public function getCreateOrUpdateFeesSuccessProvider(): iterable
    {
        yield 'with single fee' => [
            'requestData' => [
                'aliasName' => 'Withdrawal Standard',
                'merchantId' => '11111111-1111-1111-1111-111111111111',
                'brokerUserId' => 100001,
                'brokerFee' => '0.1',
                'platformFee' => '0.2',
                'revshareFee' => '0.05',
                'revshareAccountName' => 'partner',
            ],
        ];
    }

    /**
     * Test to verify create or update fees returns 204 No Content
     * @dataProvider getCreateOrUpdateFeesSuccessProvider
     */
    public function testCreateOrUpdateFeesSuccess(array $requestData): void
    {
        $this->login($this->getTestUser(['ROLE_FEES_EDIT']));
        $this->request('POST', $this->route('api.v1.fees.create-or-update-fees'), $requestData);

        $response = $this->client->getResponse();

        $this->assertEquals(204, $response->getStatusCode(), 'Response should return 204 No Content');
        $this->assertEmpty($response->getContent(), 'Response body should be empty for 204 status');
    }

    public function getCreateOrUpdateFeesEdgeCasesProvider(): iterable
    {
        yield 'with new format' => [
            204, [
                'aliasName' => 'Withdrawal Standard',
                'merchantId' => '11111111-1111-1111-1111-111111111111',
                'brokerUserId' => 100001,
                'brokerFee' => '0.1',
                'platformFee' => '0.2',
                'revshareFee' => '0.05',
                'revshareAccountName' => 'partner',
            ],
        ];
    }

    /**
     * @dataProvider getCreateOrUpdateFeesEdgeCasesProvider
     */
    public function testCreateOrUpdateFeesEdgeCases(int $status, array $requestData): void
    {
        $this->login($this->getTestUser(['ROLE_FEES_EDIT']));
        $this->request('POST', $this->route('api.v1.fees.create-or-update-fees'), $requestData);

        $response = $this->client->getResponse();

        $this->assertEquals($status, $response->getStatusCode(),
            'Unexpected status code for request: '.json_encode($requestData));
    }

    /**
     * External failure test
     */
    public function testCreateOrUpdateFeesExternalServiceDown(): void
    {
        $this->login($this->getTestUser(['ROLE_FEES_EDIT']));

        $this->getContainer()->get(GuzzleHttpMockHandler::class)->willReturn(
            'ext_test/api/v1/fees/list/aliases?broker_id=99',
            new Response(500, [], 'Internal Server Error')
        );

        $this->request('POST', $this->route('api.v1.fees.create-or-update-fees'), [
            'aliasName' => 'Withdrawal Standard',
            'merchantId' => '11111111-1111-1111-1111-111111111111',
            'brokerUserId' => 100001,
            'brokerFee' => '0.1',
            'platformFee' => '0.2',
            'revshareFee' => '0.05',
            'revshareAccountName' => 'partner',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode(),
            'Should return 400 when external service is down.');

        $content = json_decode($response->getContent(), true);
        $this->assertStringContainsString('Service unavailable atm', $content['message'],
            'Should contain fees unavailable message');
    }
}
