<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreScanRequest;
use App\Http\Resources\ScanResource;
use App\Models\Scan;
use Illuminate\Support\Str;

class ScanController extends Controller
{
    public function index()
    {
        $scans = Scan::latest()->limit(20)->get();

        return ScanResource::collection($scans);
    }

    public function store(StoreScanRequest $request)
    {
        $submittedUrl  = $request->validated()['url'];
        $normalizedUrl = rtrim($submittedUrl, '/');
        $domain        = strtolower(parse_url($normalizedUrl, PHP_URL_HOST) ?? '');

        $scan = Scan::create([
            'uuid'           => Str::uuid()->toString(),
            'submitted_url'  => $submittedUrl,
            'normalized_url' => $normalizedUrl,
            'domain'         => $domain,
            'status'         => 'queued',
        ]);

        return (new ScanResource($scan))
            ->additional(['message' => 'Scan created successfully.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $uuid)
    {
        $scan = Scan::where('uuid', $uuid)->firstOrFail();

        return new ScanResource($scan);
    }
}
