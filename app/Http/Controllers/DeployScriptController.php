<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DeployScriptController extends Controller
{
    /**
     * Whitelist of downloadable deploy scripts, binaries, and checksums.
     *
     * @var array<string, array{type: string, dirs: array<int, string>}>
     */
    private const ALLOWED_FILES = [
        'enroll.sh' => [
            'type' => 'text/plain; charset=utf-8',
            'dirs' => ['deploy'],
        ],
        'install-node.sh' => [
            'type' => 'text/plain; charset=utf-8',
            'dirs' => ['deploy'],
        ],
        'panel-agent-linux-amd64' => [
            'type' => 'application/octet-stream',
            'dirs' => ['public/deploy', 'deploy'],
        ],
        'panel-agent-linux-amd64.sha256' => [
            'type' => 'text/plain; charset=utf-8',
            'dirs' => ['public/deploy', 'deploy'],
        ],
    ];

    /**
     * Serve authorized deploy assets with secure headers.
     */
    public function __invoke(Request $request, string $script): Response
    {
        if (! isset(self::ALLOWED_FILES[$script])) {
            abort(404, 'Deploy asset not found.');
        }

        $config = self::ALLOWED_FILES[$script];
        $targetFile = null;

        foreach ($config['dirs'] as $relDir) {
            $basePath = realpath(base_path($relDir));
            $candidate = realpath(base_path($relDir.'/'.$script));

            if ($basePath && $candidate && str_starts_with($candidate, $basePath) && is_file($candidate)) {
                $targetFile = $candidate;
                break;
            }
        }

        if (! $targetFile) {
            abort(404, 'Deploy asset file not found.');
        }

        $content = file_get_contents($targetFile);
        if ($content === false) {
            abort(500, 'Unable to read deploy asset.');
        }

        return response($content, 200, [
            'Content-Type' => $config['type'],
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
