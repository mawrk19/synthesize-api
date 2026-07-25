<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Resources\ContextResource;
use App\Modules\Core\Services\ContextService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Dedoc\Scramble\Attributes\HeaderParameter;

class ContextController extends Controller
{
    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function index(ContextService $contextService)
    {
        return new ContextResource($contextService->getContext());
    }
}
