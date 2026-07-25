<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Services\AiUsageService;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\JsonResponse;

class AiUsageController extends Controller
{
    public function __construct(
        private readonly AiUsageService $aiUsageService,
    ) {}

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function summary(): JsonResponse
    {
        return response()->json([
            'data' => $this->aiUsageService->summary(),
        ]);
    }
}
