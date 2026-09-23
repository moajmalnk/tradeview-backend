<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\AccountRepository;
use App\Support\Validator;

final class AccountsController
{
    public function __construct(private AccountRepository $accounts = new AccountRepository())
    {
    }

    public function index(Request $request): void
    {
        Response::data($this->accounts->listForUser($request->user['id']));
    }

    public function show(Request $request): void
    {
        $row = $this->accounts->findForUser($request->user['id'], $request->params['id']);
        if (!$row) {
            Response::error('NOT_FOUND', 'Account not found.', 404);

            return;
        }
        Response::data($row);
    }

    public function store(Request $request): void
    {
        $v = Validator::validate($request->body, [
            'label' => 'required|string',
            'platform' => 'required|enum:mt4,mt5,tradingview,ctrader,ibkr,binance',
        ]);
        if (!$v['ok']) {
            Response::error('VALIDATION_ERROR', 'Invalid input.', 422, $v['errors']);

            return;
        }
        $row = $this->accounts->create($request->user['id'], $request->body);
        Response::data($row, 201);
    }

    public function update(Request $request): void
    {
        $row = $this->accounts->update($request->user['id'], $request->params['id'], $request->body);
        if (!$row) {
            Response::error('NOT_FOUND', 'Account not found.', 404);

            return;
        }
        Response::data($row);
    }

    public function destroy(Request $request): void
    {
        if (!$this->accounts->delete($request->user['id'], $request->params['id'])) {
            Response::error('NOT_FOUND', 'Account not found.', 404);

            return;
        }
        Response::data(['ok' => true]);
    }
}
