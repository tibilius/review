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
