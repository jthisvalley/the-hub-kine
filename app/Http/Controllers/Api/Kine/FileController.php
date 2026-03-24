<?php

namespace App\Http\Controllers\Api\Kine;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FileController extends Controller
{
    /**
     * Serve files from public storage
     */
    public function serve($path)
    {
        if (!$this->isValidPath($path)) {
            abort(403, 'Access denied.');
        }

        if (!Storage::disk('public')->exists($path)) {
            abort(404, 'File not found.');
        }

        $fullPath = Storage::disk('public')->path($path);

        $mimeType = $this->getMimeType($fullPath);

        return response()->file($fullPath, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }

    /**
     * Validate the requested path
     */
    private function isValidPath($path): bool
    {
        if (str_contains($path, '..')) {
            return false;
        }

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'txt', 'doc', 'docx'];
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return in_array(strtolower($extension), $allowedExtensions);
    }

    /**
     * Get MIME type for the file
     */
    private function getMimeType($filePath): string
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];

        return $mimeTypes[strtolower($extension)] ?? 'application/octet-stream';
    }
}
