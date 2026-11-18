<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Services\VideoUploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The browser side of uploading and web-optimising course videos
 * (2026-10-10). Every action answers JSON for the page's script; the work is
 * in App\Services\VideoUploads and App\Services\FastStartJob.
 *
 * Reached only through the manage route group (super admin, admin, lecturer) —
 * the same people who already attach videos on this screen.
 */
class CourseVideoUploadController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'title' => ['nullable', 'string', 'max:180'],
            'file_name' => ['required', 'string', 'max:255'],
            'file_size' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json(VideoUploads::start($request->user(), $data));
    }

    public function chunk(Request $request, string $upload): JsonResponse
    {
        $data = $request->validate([
            'offset' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file'],
        ]);

        try {
            $received = VideoUploads::chunk($request->user(), $upload, (int) $data['offset'], $request->file('chunk'));
        } catch (HttpException $e) {
            // Out of order: tell the browser where to carry on from.
            if ($e->getStatusCode() === 409 && ctype_digit($e->getMessage())) {
                return response()->json(['received' => (int) $e->getMessage(), 'message' => 'Resuming from the right place.'], 409);
            }

            throw $e;
        }

        return response()->json(['received' => $received]);
    }

    public function finish(Request $request, string $upload): JsonResponse
    {
        return response()->json(VideoUploads::finish($request->user(), $upload));
    }

    public function step(Request $request, string $job): JsonResponse
    {
        return response()->json(VideoUploads::step($request->user(), $job));
    }

    /** "Make web-optimised" on a file that is already in the library. */
    public function optimise(Request $request): JsonResponse
    {
        $data = $request->validate(['video_path' => ['required', 'string', 'max:1000']]);

        return response()->json(VideoUploads::optimiseInPlace($data['video_path']));
    }
}
