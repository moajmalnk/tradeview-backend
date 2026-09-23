<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\ProfileRepository;

final class ProfileController
{
    public function __construct(private ProfileRepository $profiles = new ProfileRepository())
    {
    }

    public function show(Request $request): void
    {
        $profile = $this->profiles->find($request->user['id']);
        if (!$profile) {
            Response::error('NOT_FOUND', 'Profile not found.', 404);

            return;
        }
        Response::data($profile);
    }

    public function update(Request $request): void
    {
        $profile = $this->profiles->update($request->user['id'], $request->body);
        Response::data($profile);
    }
}
