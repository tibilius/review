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
