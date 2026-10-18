<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\ConnectorTokenRepository;
use App\Support\Validator;

final class TokensController
{
    public function __construct(private ConnectorTokenRepository $tokens = new ConnectorTokenRepository())
    {
    }

    public function index(Request $request): void
    {
        Response::data($this->tokens->list($request->user['id']));
    }

    public function store(Request $request): void
    {
        $v = Validator::validate($request->body, [
            'platform' => 'required|enum:mt4,mt5,tradingview,ctrader,ibkr,binance',
        ]);
        if (!$v['ok']) {
            Response::error('VALIDATION_ERROR', 'Invalid input.', 422, $v['errors']);

            return;
        }

        $created = $this->tokens->create(
            $request->user['id'],
            (string) $request->body['platform'],
            isset($request->body['account_id']) ? (string) $request->body['account_id'] : null,
        );

        Response::data([
            'token' => $created['plain'],
            'record' => $created['row'],
        ], 201);
    }

    /**
     * Active → revoke (EA stops; row kept). Already revoked/expired → hard delete.
     * Keeps delete working on hosts that only expose DELETE /connector-tokens/{id}.
     */
    public function destroy(Request $request): void
    {
        $userId = $request->user['id'];
        $id = $request->params['id'];

        if ($this->tokens->revoke($userId, $id)) {
            Response::data(['ok' => true]);

            return;
        }

        if ($this->tokens->deleteInactive($userId, $id)) {
            Response::data(['ok' => true]);

            return;
        }

        Response::error('NOT_FOUND', 'Token not found.', 404);
    }

    /** Active tokens must be revoked first so a live EA is never cut off by a delete. */
    public function purge(Request $request): void
    {
        if (!$this->tokens->deleteInactive($request->user['id'], $request->params['id'])) {
            Response::error('NOT_FOUND', 'Token not found or still active.', 404);

            return;
        }
        Response::data(['ok' => true]);
    }

    public function purgeInactive(Request $request): void
    {
        Response::data(['deleted' => $this->tokens->deleteAllInactive($request->user['id'])]);
    }
}
