# Code Review — Fees (createOrUpdateFees)

## Контекст

Админ-интерфейс для управления комиссиями мерчантов.

Эндпоинт принимает `aliasName` (например `"Withdrawal Standard"`), `merchantId` и до трёх значений комиссии (`brokerFee`, `platformFee`, `revshareFee`) с именем revshare-аккаунта. Задача эндпоинта — применить эти комиссии на стороне внешнего сервиса и записать историю изменений.

## Что нужно сделать

Перед тобой четыре файла:

1. **Request DTO** — `CreateOrUpdateFeesReq.php`
2. **Controller** — `FeesController.php`, метод `createOrUpdateFees()`
3. **Repository** — `FeesRepository.php`, методы `createOrUpdateFees()` и `createOrUpdateFeesLowLevel()`
4. **Functional tests** — `FeesControllerTest.php`, тесты на `createOrUpdateFees*`

У тебя ~15 минут. Сначала смотри продакшен-код, потом перейдём к тестам.

Читай, задавай вопросы, говори что видишь — стиль, структура, бизнес-логика, тесты, всё что замечаешь.

---

## Исходники

### 1. Request DTO — `App\Api\V1\Fees\Request\CreateOrUpdateFeesReq`

```php
<?php

declare(strict_types=1);

namespace App\Api\V1\Fees\Request;

use App\Api\Request\RequestParamInterface;
use Symfony\Component\Validator\Constraints as Assert;

class CreateOrUpdateFeesReq implements RequestParamInterface
{
    /**
     * @Assert\NotBlank
     * @Assert\Type("string")
     */
    private string $aliasName;

    /**
     * @Assert\NotBlank
     * @Assert\Type("string")
     */
    private string $merchantId;

    /**
     * @Assert\NotBlank
     * @Assert\Type("int")
     */
    private int $brokerUserId;

    private ?string $brokerFee;
    private ?string $platformFee;
    private ?string $revshareFee;
    private ?string $revshareAccountName;

    public function __construct(
        string $aliasName,
        string $merchantId,
        int $brokerUserId,
        ?string $brokerFee = null,
        ?string $platformFee = null,
        ?string $revshareFee = null,
        ?string $revshareAccountName = null,
    ) {
        $this->aliasName = $aliasName;
        $this->merchantId = $merchantId;
        $this->brokerUserId = $brokerUserId;
        $this->brokerFee = $brokerFee;
        $this->platformFee = $platformFee;
        $this->revshareFee = $revshareFee;
        $this->revshareAccountName = $revshareAccountName;
    }

    public function getAliasName(): string
    {
        return $this->aliasName;
    }

    public function getMerchantId(): string
    {
        return $this->merchantId;
    }

    public function getBrokerUserId(): int
    {
        return $this->brokerUserId;
    }

    public function getBrokerFee(): ?string
    {
        return $this->brokerFee;
    }

    public function getPlatformFee(): ?string
    {
        return $this->platformFee;
    }

    public function getRevshareFee(): ?string
    {
        return $this->revshareFee;
    }

    public function getRevshareAccountName(): ?string
    {
        return $this->revshareAccountName;
    }
}
```

---

### 2. Controller — `App\Controller\Api\V1\Fees\FeesController`

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api\V1\Fees;

use App\Api\V1\Fees\Request\CreateOrUpdateFeesReq;
use App\Repository\FeesRepository;
use Nelmio\ApiDocBundle\Annotation\Model;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Swagger\Annotations as SWG;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/api/v1/fees")
 * @SWG\Tag(name="Fees")
 */
class FeesController extends AbstractController
{
    /**
     * @Route("/create-or-update-fees", name="api.v1.fees.create-or-update-fees", methods={"POST"})
     * @Security("is_granted('ROLE_FEES_EDIT')")
     * @SWG\Parameter(
     *     name="data",
     *     parameter="data",
     *     in="body",
     *     @SWG\Schema(ref=@Model(type=CreateOrUpdateFeesReq::class))
     * )
     *
     * @SWG\Response(
     *     response=204,
     *     description="Successfully created or updated fees"
     * )
     * @SWG\Response(
     *     response=400,
     *     description="Fees api unavailable, try to request later"
     * )
     */
    public function createOrUpdateFees(
        CreateOrUpdateFeesReq $request,
        FeesRepository $feesRepository,
    ): Response {
        $feesRepository->createOrUpdateFees($request);

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
```

---

### 3. Repository — `App\Repository\FeesRepository`

```php
class FeesRepository
{
    use ApiErrorHandlingTrait;

    // ... constructor, constants, other methods omitted ...

    public function createOrUpdateFees(CreateOrUpdateFeesReq $request): void
    {
        /** @var \ExtApi\Api\Merchant\MerchantData $merchant */
        $merchant = $this->handleApiError(
            fn () => $this->restApi->getMerchantById(
                $request->getMerchantId(),
            ),
        );
        $brokerId = $merchant->getBrokerId();

        // Fetch aliases to get operation types for the specified alias name
        $aliasesListResponse = $this->listAliasesByBrokerId(
            new ListAliasesByBrokerIdReq($brokerId),
        );

        $operationTypes = [];
        foreach ($aliasesListResponse->getData()->getAliases() as $alias) {
            if ($alias->getName() === $request->getAliasName()) {
                $operationTypes = $alias->getOperationTypes();
                break;
            }
        }

        $brokerUserId = $request->getBrokerUserId();

        // Fetch existing swap overrides to get bank account IDs for all account types
        $swapOverridesResponse = $this->listSwapOverrides(
            new ListSwapOverridesReq($brokerUserId, $operationTypes, $brokerId),
        );

        // Build maps of account type -> bank_account_id from existing fees
        $accountTypeToBankAccountId = [];

        // Check both fees and defaults for bank account IDs
        $allFees = array_merge(
            $swapOverridesResponse->getData()->getFees(),
            $swapOverridesResponse->getData()->getDefaults(),
        );

        foreach ($allFees as $feeData) {
            $accountType = $feeData->getCommissionAccount()->getAccountType();
            $bankAccountId = $feeData
                ->getCommissionAccount()
                ->getBankAccountId()
            ;

            // Store the bank account ID for each account type
            if (
                !isset($accountTypeToBankAccountId[$accountType])
                && !empty($bankAccountId)
            ) {
                $accountTypeToBankAccountId[$accountType] = $bankAccountId;
            }
        }

        // Also check revshare accounts for custom account bank IDs
        $revshareAccountsResponse = $this->listRevshareAccounts(
            new ListRevshareAccountsReq(
                $brokerUserId,
                $request->getMerchantId(),
            ),
        );

        // Build a map of account name -> bank_account_id for custom accounts
        $customAccountNameToBankAccountId = [];
        foreach (
            $revshareAccountsResponse->getData()->getAccounts() as $account
        ) {
            $customAccountNameToBankAccountId[
                $account->getName()
            ] = $account->getBankAccountId();
        }

        // Convert to broker API format - create a Fee object for each operation type and fee type
        $fees = [];

        // Add platform fee if provided
        if (null !== $request->getPlatformFee()) {
            $platformBankAccountId =
                $accountTypeToBankAccountId[self::ACCOUNT_TYPE_PLATFORM] ?? '';

            foreach ($operationTypes as $operationType) {
                $feeCollectorAccount = new FeeCollectorAccount(
                    self::ACCOUNT_TYPE_PLATFORM,
                    self::ACCOUNT_NAME_PLATFORM,
                    $platformBankAccountId,
                );

                $fees[] = new Fee(
                    $brokerUserId,
                    $request->getPlatformFee(),
                    $operationType,
                    $feeCollectorAccount,
                );
            }
        }

        // Add broker fee if provided
        if (null !== $request->getBrokerFee()) {
            $brokerBankAccountId =
                $accountTypeToBankAccountId[self::ACCOUNT_TYPE_BROKER] ?? '';

            foreach ($operationTypes as $operationType) {
                $feeCollectorAccount = new FeeCollectorAccount(
                    self::ACCOUNT_TYPE_BROKER,
                    self::ACCOUNT_NAME_BROKER,
                    $brokerBankAccountId,
                );

                $fees[] = new Fee(
                    $brokerUserId,
                    $request->getBrokerFee(),
                    $operationType,
                    $feeCollectorAccount,
                );
            }
        }

        // Add revshare fee if provided
        if (null !== $request->getRevshareFee()) {
            $revshareAccountName = $request->getRevshareAccountName() ?? '';
            $revshareBankAccountId =
                $customAccountNameToBankAccountId[$revshareAccountName] ?? '';

            foreach ($operationTypes as $operationType) {
                $feeCollectorAccount = new FeeCollectorAccount(
                    self::ACCOUNT_TYPE_CUSTOM,
                    $revshareAccountName,
                    $revshareBankAccountId,
                );

                $fees[] = new Fee(
                    $brokerUserId,
                    $request->getRevshareFee(),
                    $operationType,
                    $feeCollectorAccount,
                );
            }
        }

        // Create broker API request with Fee objects
        $brokerApiRequest = new ExtCreateOrUpdateFeesRequest($fees);

        $this->createOrUpdateFeesLowLevel($brokerApiRequest, $brokerId);
    }

    private function createOrUpdateFeesLowLevel(
        ExtCreateOrUpdateFeesRequest $request,
        int $brokerId,
    ): void {
        $response = $this->handleApiError(function () use ($request, $brokerId) {
            return $this->brokerApi->createOrUpdateFees($request, $brokerId);
        });

        foreach ($response->getUpdates() as $update) {
            $historyData = [
                'broker_id' => $brokerId,
                'user_id' => $update->getUserId(),
                'saved_data' => $update->getSavedData(),
                'previous_data' => $update->getPreviousData(),
            ];

            $this->historyService->add(
                'merchant_fee_changes',
                null === $update->getPreviousData()
                    ? HistoryService::CREATE
                    : HistoryService::UPDATE,
                (string) $update->getUserId(),
                $historyData,
            );
        }
    }
}
```

---

### 4. Functional tests — `Tests\Functional\Api\Fees\FeesControllerTest`

```php
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
```
