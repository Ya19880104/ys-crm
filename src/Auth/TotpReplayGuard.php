<?php

declare(strict_types=1);

namespace YangSheep\CRM\Auth;

use YangSheep\CRM\Core\Database;

/**
 * Atomically consumes a TOTP time step.
 *
 * Verifying the code and then comparing a previously-read totp_last_step is
 * not sufficient: concurrent requests can both observe the same watermark.
 * The affected-row CAS below is the authorization boundary; exactly one
 * request may advance a user's watermark to a given step.
 */
final class TotpReplayGuard
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function consume(int $userId, int $step): bool
    {
        if ($userId <= 0 || $step < 0) {
            return false;
        }

        return $this->db->execute(
            "UPDATE {prefix}users
             SET totp_last_step = :step
             WHERE id = :id
               AND status = 'active'
               AND totp_enabled = 1
               AND (totp_last_step IS NULL OR totp_last_step < :step)",
            ['step' => $step, 'id' => $userId]
        ) === 1;
    }
}
