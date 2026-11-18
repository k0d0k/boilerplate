<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Streams the AUTHENTICATED account's OWN profile photo.
 *
 * A profile photo is written to a private disk with no public URL, so it is
 * only ever read back here. The identity is resolved from the session — never
 * from a route parameter — so this endpoint can only ever return the signed-in
 * account's photo, and a signed-out or photoless account gets a 404.
 */
final class AccountPhotoController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $user = $request->user();
        $path = $user instanceof User ? $user->photo_path : null;
        $disk = Storage::disk((string) config('lba.accounts.photo_disk', 'account-photos'));

        if ($path === null || $path === '' || ! $disk->exists($path)) {
            throw new NotFoundHttpException;
        }

        return $disk->response($path, null, [
            // Never cached across identities on a shared device.
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
        ]);
    }
}
