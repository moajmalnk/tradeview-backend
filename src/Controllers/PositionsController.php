<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\PositionRepository;

final class PositionsController
{
    public function __construct(private PositionRepository $positions = new PositionRepository())
    {
    }

    public function index(Request $request): void
    {
        $accountId = isset($request->query['account_id']) ? (string) $request->query['account_id'] : null;
        Response::data($this->positions->listOpen($request->user['id'], $accountId));
    }
}
