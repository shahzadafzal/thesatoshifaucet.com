<?php
// faucet-balance-lib.php
// One place that works out the faucet balance, so it can never drift (see add-faucet-funding.sql):
//
//   balance = everything funded (faucet_funding)
//           - sats_sent of paid claims
//           - sats_requested of pending/processing claims (reserved, not sent yet)
//
// claim.php and Block Race still take a new claim's sats off straight away (that keeps the "is there
// enough?" check race-free). Everything that changes a claim afterwards (admin status edits, manual
// payouts, scheduler refunds) calls faucet_recalc_balance(), which sets the balance from the formula.
// If the faucet_funding table hasn't been created yet, nothing is changed.

if (!function_exists('faucet_recalc_balance')) {

    // Returns the new balance, or null if the funding table doesn't exist yet.
    function faucet_recalc_balance(PDO $pdo): ?int {
        if (!faucet_funding_ready($pdo)) return null;
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            // Lock the balance row first: the same lock claim.php takes, so a new claim can't slip in mid-way.
            $pdo->query("SELECT balance_sats FROM faucet_balance WHERE id = 1 FOR UPDATE")->fetchColumn();
            $t = faucet_balance_totals($pdo);
            $pdo->prepare("UPDATE faucet_balance SET balance_sats = :b WHERE id = 1")->execute([':b' => $t['balance']]);
            if ($own) $pdo->commit();
            return $t['balance'];
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    // The parts of the formula, for showing in the admin panel.
    function faucet_balance_totals(PDO $pdo): array {
        $funded   = (int)$pdo->query("SELECT COALESCE(SUM(amount_sats), 0) FROM faucet_funding")->fetchColumn();
        $sent     = (int)$pdo->query("SELECT COALESCE(SUM(sats_sent), 0) FROM faucet_claims WHERE status = 'paid'")->fetchColumn();
        $reserved = (int)$pdo->query("SELECT COALESCE(SUM(sats_requested), 0) FROM faucet_claims WHERE status IN ('pending','processing')")->fetchColumn();
        return [
            'funded'   => $funded,
            'sent'     => $sent,
            'reserved' => $reserved,
            'balance'  => max(0, $funded - $sent - $reserved),
        ];
    }

    // Add money to the faucet (donation, top-up) or a correction (negative amount), then recalculate.
    function faucet_add_funding(PDO $pdo, int $amount, string $kind, string $note): int {
        $kinds = ['initial', 'donation', 'topup', 'adjustment'];
        if (!in_array($kind, $kinds, true)) $kind = 'topup';
        $pdo->prepare("INSERT INTO faucet_funding (amount_sats, kind, note) VALUES (:a, :k, :n)")
            ->execute([':a' => $amount, ':k' => $kind, ':n' => mb_substr($note, 0, 255)]);
        return (int)faucet_recalc_balance($pdo);
    }

    function faucet_funding_ready(PDO $pdo): bool {
        static $ready = null;
        if ($ready === null) {
            $ready = (bool)$pdo->query("SHOW TABLES LIKE 'faucet_funding'")->fetchColumn();
        }
        return $ready;
    }
}
