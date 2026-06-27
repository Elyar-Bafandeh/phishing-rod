<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Scan;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ScanController extends Controller
{
    public function index()
    {
        $scans = Scan::latest()
            ->limit(20)
            ->get();

        return response()->json([
            'data' => $scans,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        $submittedUrl = $validated['url'];
        $normalizedUrl = rtrim($submittedUrl, '/');
        $domain = parse_url($normalizedUrl, PHP_URL_HOST);

        $scan = Scan::create([
            'uuid' => Str::uuid()->toString(),
            'submitted_url' => $submittedUrl,
            'normalized_url' => $normalizedUrl,
            'domain' => $domain,
            'status' => 'queued',
        ]);

        return response()->json([
            'message' => 'Scan created successfully.',
            'data' => $scan,
        ], 201);
    }

    public function show(string $uuid)
    {
        $scan = Scan::where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'data' => $scan,
        ]);
    }
}
