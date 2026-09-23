<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\AccountRepository;
use App\Repositories\TradeRepository;
use App\Support\Uuid;
use App\Support\Validator;

final class TradesController
{
    public function __construct(
        private TradeRepository $trades = new TradeRepository(),
        private AccountRepository $accounts = new AccountRepository(),
    ) {
    }

    public function index(Request $request): void
    {
        $accountId = isset($request->query['account_id']) ? (string) $request->query['account_id'] : null;
        $limit = $request->intQuery('limit', 50, 1, 100);
        $offset = $request->intQuery('offset', 0, 0);
        Response::data($this->trades->list($request->user['id'], $accountId, $limit, $offset));
    }

    public function show(Request $request): void
    {
        $row = $this->trades->find($request->user['id'], $request->params['id']);
        if (!$row) {
            Response::error('NOT_FOUND', 'Trade not found.', 404);

            return;
        }
        Response::data($row);
    }

    public function store(Request $request): void
    {
        $v = Validator::validate($request->body, [
            'account_id' => 'required|string',
            'symbol' => 'required|string',
            'direction' => 'required|enum:buy,sell',
            'volume' => 'required|number',
            'entry_price' => 'required|number',
            'open_time' => 'required|string',
        ]);
        if (!$v['ok']) {
            Response::error('VALIDATION_ERROR', 'Invalid input.', 422, $v['errors']);

            return;
        }
        if (!$this->accounts->findForUser($request->user['id'], (string) $request->body['account_id'])) {
            Response::error('NOT_FOUND', 'Account not found.', 404);

            return;
        }

        $payload = $request->body;
        $payload['ticket'] = $payload['ticket'] ?? ('MAN-' . substr(Uuid::v4(), 0, 8));
        $meta = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $meta['manual'] = true;
        $payload['metadata'] = $meta;

        $row = $this->trades->create($request->user['id'], $payload);
        Response::data($row, 201);
    }

    public function update(Request $request): void
    {
        $row = $this->trades->update($request->user['id'], $request->params['id'], $request->body);
        if (!$row) {
            Response::error('NOT_FOUND', 'Trade not found.', 404);

            return;
        }
        Response::data($row);
    }

    public function destroy(Request $request): void
    {
        if (!$this->trades->delete($request->user['id'], $request->params['id'])) {
            Response::error('NOT_FOUND', 'Trade not found.', 404);

            return;
        }
        Response::data(['ok' => true]);
    }
}
