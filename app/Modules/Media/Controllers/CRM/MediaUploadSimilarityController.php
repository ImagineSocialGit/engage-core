<?php

namespace App\Modules\Media\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Media\Requests\StoreMediaAssetRequest;
use App\Modules\Media\Services\MediaImageSimilarityInspector;
use Illuminate\Http\JsonResponse;

final class MediaUploadSimilarityController extends Controller
{
    public function __invoke(
        StoreMediaAssetRequest $request,
        MediaImageSimilarityInspector $similarityInspector,
    ): JsonResponse {
        return response()->json(
            $similarityInspector->inspect($request->file('file')),
        );
    }
}