<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\AuditRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\UserRepository;
use App\Services\JwtService;
use App\Support\Validator;

final class AuthController
{
    public function __construct(
        private UserRepository $users = new UserRepository(),
        private ProfileRepository $profiles = new ProfileRepository(),
        private JwtService $jwt = new JwtService(),
        private AuditRepository $audit = new AuditRepository(),
    ) {
    }

    public function register(Request $request): void
    {
        $v = Validator::validate($request->body, [
            'email' => 'required|email',
            'password' => 'required|string|min:4',
            'full_name' => 'string',
        ]);
        if (!$v['ok']) {
            Response::error('VALIDATION_ERROR', 'Invalid input.', 422, $v['errors']);

            return;
        }

        if ($this->users->findByEmail($v['data']['email'])) {
            Response::error('EMAIL_TAKEN', 'Email already registered.', 409);

            return;
        }

        $user = $this->users->create(
            $v['data']['email'],
            password_hash((string) $request->body['password'], PASSWORD_BCRYPT),
            isset($request->body['full_name']) ? (string) $request->body['full_name'] : null,
        );
        $this->audit->log($user['id'], 'USER_REGISTERED', $user['email']);
        $token = $this->jwt->issue($user['id'], $user['email']);
        $profile = $this->profiles->find($user['id']);

        Response::data([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'profile' => $profile,
            ],
        ], 201);
    }

    public function login(Request $request): void
    {
        $v = Validator::validate($request->body, [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);
        if (!$v['ok']) {
            Response::error('VALIDATION_ERROR', 'Invalid input.', 422, $v['errors']);

            return;
        }

        $user = $this->users->findByEmail($v['data']['email']);
        if (!$user || !password_verify((string) $request->body['password'], $user['password_hash'])) {
            Response::error('INVALID_CREDENTIALS', 'Invalid email or password.', 401);

            return;
        }

        $token = $this->jwt->issue($user['id'], $user['email']);
        $profile = $this->profiles->find($user['id']);
        $this->audit->log($user['id'], 'USER_LOGIN', $user['email']);

        Response::data([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'profile' => $profile,
            ],
        ]);
    }

    public function me(Request $request): void
    {
        $userId = $request->user['id'];
        $user = $this->users->findById($userId);
        $profile = $this->profiles->find($userId);
        if (!$user) {
            Response::error('NOT_FOUND', 'User not found.', 404);

            return;
        }

        Response::data([
            'id' => $user['id'],
            'email' => $user['email'],
            'profile' => $profile,
        ]);
    }

    public function logout(Request $request): void
    {
        Response::data(['ok' => true]);
    }
}
