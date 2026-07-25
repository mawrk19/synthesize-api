<?php

namespace App\Modules\Authorization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authorization\Services\PermissionService;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\Resources\Json\JsonResource;

class PermissionController extends Controller
{
    public function __construct(
        private PermissionService $permissionService
    ) {}

    #[HeaderParameter('Authorization', 'The authorization token', true)]
    public function index()
    {
        return JsonResource::collection($this->permissionService->getAllPermissions());
    }
}
