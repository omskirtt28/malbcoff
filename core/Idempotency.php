<?php
/**
 * Safe retries (Patch 6a). A client generates one UUID per user intention ("Save sale", "Receive stock"…)
 * and sends it with the request (header X-Idempotency-Key, or field `client_key`). The first request that
 * carries a key does its work and stores the key + result in `idempotency_keys` INSIDE the same DB
 * transaction; any later request with the same key just gets the stored result back — no second record,
 * no second stock movement. Requests without a key (or before migration P3_002 is imported) behave as before.
 */
final class Idempotency
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** True when the caller asked for JSON (fetch) instead of the classic redirect + flash page flow. */
    public static function wantsJson(): bool
    {
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        return str_contains($accept, 'application/json')
            || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    /** Valid client key or null (a missing/invalid key simply means "no retry protection", as before). */
    public static function key(?array $payload = null): ?string
    {
        $raw = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? ($payload['client_key'] ?? ($_POST['client_key'] ?? null));
        if (!is_string($raw)) return null;
        $raw = trim($raw);
        if (!preg_match(self::UUID, $raw)) return null;
        return self::ready() ? strtolower($raw) : null;
    }

    /** The table exists (migration P3_002 imported). */
    public static function ready(): bool
    {
        static $ready = null;
        if ($ready !== null) return $ready;
        try {
            $ready = (int)Database::query(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='idempotency_keys'"
            )->fetchColumn() > 0;
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }

    /** Hash of what the user submitted, ignoring the token/key fields that legitimately change between attempts. */
    public static function fingerprint(array $payload): string
    {
        // receive_nonce is a per-page-view token (Receive Stock review); the offline outbox (Patch 6c-1) does not store it,
        // so a resend of a request whose answer was lost must still match the first attempt.
        // offline_* (Patch 6c-2) only exist on a sale that was queued on the device: the first online attempt of the same
        // sale did not carry them, and a resend must still match it.
        unset($payload['_csrf'], $payload['client_key'], $payload['receive_nonce'], $payload['offline_device_time'], $payload['offline_receipt_no']);
        $sort = static function (&$value) use (&$sort): void {
            if (is_array($value)) {
                ksort($value);
                foreach ($value as &$child) $sort($child);
            }
        };
        $sort($payload);
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
    }

    /**
     * Stored result for a key that already completed, or null. Throws RuntimeException when the key was
     * used for a different action / user / content (never silently replays the wrong result).
     */
    public static function lookup(string $key, string $action, int $userId, string $fingerprint): ?array
    {
        $row = Database::query(
            'SELECT action,user_id,request_hash,result_json FROM idempotency_keys WHERE client_key=? LIMIT 1',
            [$key]
        )->fetch();
        if (!$row) return null;
        if ($row['action'] !== $action || (int)$row['user_id'] !== $userId || !hash_equals((string)$row['request_hash'], $fingerprint)) {
            throw new RuntimeException('This request was already used for something different. Refresh the page and try again.');
        }
        $result = json_decode((string)$row['result_json'], true);
        return is_array($result) ? $result : null;
    }

    /**
     * Reserve the key. Call as the FIRST statement inside the transaction. Returns false when another
     * request already holds/finished this key (a concurrent duplicate waits here for the winner to commit).
     */
    public static function claim(string $key, string $action, int $userId, string $fingerprint): bool
    {
        try {
            Database::query(
                'INSERT INTO idempotency_keys (client_key,action,user_id,request_hash) VALUES (?,?,?,?)',
                [$key, $action, $userId, $fingerprint]
            );
            return true;
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') return false;
            throw $e;
        }
    }

    /** Store the response body with the key; call right before commit so both succeed or fail together. */
    public static function complete(string $key, array $result, ?string $referenceNo = null): void
    {
        Database::query(
            'UPDATE idempotency_keys SET result_json=?,reference_no=? WHERE client_key=?',
            [json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $referenceNo !== null ? mb_substr($referenceNo, 0, 100) : null, $key]
        );
    }

    /** If this key already finished, answer with the stored result (flagged replayed) and stop. 409 on key misuse. */
    public static function replayJsonAndExit(?string $key, string $action, int $userId, string $fingerprint): void
    {
        if ($key === null) return;
        try {
            $prior = self::lookup($key, $action, $userId, $fingerprint);
        } catch (RuntimeException $e) {
            self::send(409, ['ok' => false, 'success' => false, 'error' => $e->getMessage(), 'code' => 'key_conflict']);
        }
        if ($prior !== null) self::send(200, $prior + ['replayed' => true]);
    }

    /** Same as replayJsonAndExit for the moment right after a lost claim (the winner has committed by now). */
    public static function replayAfterLostClaim(string $key, string $action, int $userId, string $fingerprint): never
    {
        try {
            $prior = self::lookup($key, $action, $userId, $fingerprint);
        } catch (RuntimeException $e) {
            self::send(409, ['ok' => false, 'success' => false, 'error' => $e->getMessage(), 'code' => 'key_conflict']);
        }
        if ($prior === null) {
            self::send(409, ['ok' => false, 'success' => false, 'error' => 'This request is still being saved. Please wait a moment and try again.', 'code' => 'in_progress']);
        }
        self::send(200, $prior + ['replayed' => true]);
    }

    /** CSRF/session failures in JSON mode carry a fresh token so the client can retry without a page reload. */
    public static function csrfFailure(): never
    {
        self::send(419, [
            'ok' => false, 'success' => false, 'code' => 'csrf',
            'error' => 'Your session expired. Refresh the page and try again.',
            'csrf' => Auth::check() ? Csrf::token() : null,
        ]);
    }

    public static function send(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
