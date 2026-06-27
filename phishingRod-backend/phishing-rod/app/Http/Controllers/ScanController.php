<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ScanController extends Controller
{
    public function create()
    {
        return view('scans.create');
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

        return redirect()->route('scans.show', $scan->uuid);
    }

    public function show(string $uuid)
    {
        $scan = Scan::where('uuid', $uuid)->firstOrFail();

        return view('scans.show', [
            'scan' => $scan,
        ]);
    }
}
