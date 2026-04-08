<?php

declare(strict_types=1);

namespace App\Repository;

use App\Api\V1\Fees\Request\CreateOrUpdateFeesReq;
use App\Api\V1\Fees\Request\ListAliasesByBrokerIdReq;
use App\Api\V1\Fees\Request\ListRevshareAccountsReq;
use App\Api\V1\Fees\Request\ListSwapOverridesReq;
use App\Api\V1\Fees\Response\ListAliasesByBrokerIdRes;
use App\Api\V1\Fees\Response\ListRevshareAccountsRes;
use App\Api\V1\Fees\Response\ListSwapOverridesRes;
use App\Controller\Api\ApiErrorHandlingTrait;
use App\Services\HistoryService;
use ExtApi\Api\Fees\CreateOrUpdateFeesRequest as ExtCreateOrUpdateFeesRequest;
use ExtApi\Api\Fees\Fee;
use ExtApi\Api\Fees\FeeCollectorAccount;
use ExtApi\Handler\FeesRestApi;
use ExtApi\Handler\RestApi;

class FeesRepository
{
    use ApiErrorHandlingTrait;

    private FeesRestApi $brokerApi;
    private RestApi $restApi;
    private HistoryService $historyService;

    private const ACCOUNT_TYPE_PLATFORM = 'ACCOUNT_TYPE_PLATFORM';
    private const ACCOUNT_TYPE_BROKER = 'ACCOUNT_TYPE_BROKER';
    private const ACCOUNT_TYPE_CUSTOM = 'ACCOUNT_TYPE_CUSTOM';

    private const ACCOUNT_NAME_PLATFORM = 'platform';
    private const ACCOUNT_NAME_BROKER = 'broker';

    public function __construct(
        FeesRestApi $brokerApi,
        RestApi $restApi,
        HistoryService $historyService,
    ) {
        $this->brokerApi = $brokerApi;
        $this->restApi = $restApi;
        $this->historyService = $historyService;
    }

    // ... listAliasesByBrokerId / listSwapOverrides / listRevshareAccounts omitted ...

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

    // ... resetFees / getCommissions / getCommissionDetails omitted ...
}
