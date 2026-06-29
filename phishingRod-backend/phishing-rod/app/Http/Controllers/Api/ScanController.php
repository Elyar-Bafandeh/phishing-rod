<?php

namespace App\Http\Controllers\Api;

use App\Actions\Scans\CreateScanAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreScanRequest;
use App\Http\Resources\ScanResource;
use App\Models\Scan;

class ScanController extends Controller
{
    public function index()
    {
        $scans = Scan::latest()->limit(20)->get();

        return ScanResource::collection($scans);
    }

    public function store(StoreScanRequest $request, CreateScanAction $createScanAction)
    {
        $scan = $createScanAction->execute($request->validated('url'));

        return (new ScanResource($scan))
            ->additional(['message' => 'Scan created successfully.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $uuid)
    {
        $scan = Scan::with('predictions')->where('uuid', $uuid)->firstOrFail();

        return new ScanResource($scan);
    }
}
