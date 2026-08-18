<?php

declare(strict_types=1);

namespace App\Http\Controllers\Production;

use App\Domain\Production\Actions\SubmitCuttingTaskAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Production\SubmitCuttingTaskRequest;
use Illuminate\Http\JsonResponse;

class CuttingTaskController extends Controller
{
    public function store(SubmitCuttingTaskRequest $request, SubmitCuttingTaskAction $action): JsonResponse
    {
        $task = $action->execute($request->validated(), (int) $request->user()->id);

        return response()->json(['data' => $task], 201);
    }
}
