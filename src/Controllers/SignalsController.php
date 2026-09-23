<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\SignalRepository;
use App\Support\Validator;

final class SignalsController
{
    public function __construct(private SignalRepository $signals = new SignalRepository())
    {
    }

    public function index(Request $request): void
    {
        $limit = $request->intQuery('limit', 50, 1, 100);
        $offset = $request->intQuery('offset', 0, 0);
        Response::data($this->signals->list($request->user['id'], $limit, $offset));
    }

    public function show(Request $request): void
    {
        $row = $this->signals->find($request->user['id'], $request->params['id']);
        if (!$row) {
            Response::error('NOT_FOUND', 'Signal not found.', 404);

            return;
        }
        Response::data($row);
    }

    public function store(Request $request): void
    {
        $v = Validator::validate($request->body, [
            'symbol' => 'required|string',
            'direction' => 'required|enum:buy,sell',
        ]);
        if (!$v['ok']) {
            Response::error('VALIDATION_ERROR', 'Invalid input.', 422, $v['errors']);

            return;
        }
        $row = $this->signals->create($request->user['id'], $request->body);
        Response::data($row, 201);
    }
}
