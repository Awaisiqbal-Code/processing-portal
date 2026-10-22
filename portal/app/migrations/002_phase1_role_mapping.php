<?php
declare(strict_types=1);

/**
 * Migration 002: Role Mapping & Processing Staff Assignment
 *
 * Mapped according to the updated Backend & Frontend CSV dashboards:
 * - 5 Processing Officers:
 *     - Backend PO: Minahil (id=2), Sehrish (id=3), Farwa (id=4) -> processing_staff (dept: backend)
 *     - Frontend PO: Nimra (id=510), Mujassam (id=511) -> processing_staff (dept: frontend)
 * - Team Lead / PMS Lead:
 *     - Mubeen Fatima ('TEAM LEAD REMARKS - Mubeen Fatima' in Backend CSV) -> created as pms_lead (dept: backend)
 * - Admin Slot:
 *     - Left empty (0/1) for Super Admin to assign on demand.
 * - Counselors:
 *     - All 19 canonical counselors verified against CSV caseloads (all have active student assignments).
 */
return function (PDO $pdo): array {
    // 1. Map existing 5 officers to processing_staff
    $pdo->exec("
        UPDATE users
        SET role = 'processing_staff', department = 'backend'
        WHERE id IN (2, 3, 4)
    ");
    $pdo->exec("
        UPDATE users
        SET role = 'processing_staff', department = 'frontend'
        WHERE id IN (510, 511)
    ");

    // 2. Ensure Team Lead 'Mubeen Fatima' exists as pms_lead
    $checkLead = $pdo->prepare("SELECT id, role FROM users WHERE LOWER(username) = 'mubeen.fatima'");
    $checkLead->execute();
    $existingLead = $checkLead->fetch();

    $leadId = 0;
    if (!$existingLead) {
        $pw = random_password(14);
        $insLead = $pdo->prepare("
            INSERT INTO users (name, username, password_hash, role, active, must_change_password, created_at, department)
            VALUES ('Mubeen Fatima', 'mubeen.fatima', ?, 'pms_lead', 1, 1, datetime('now'), 'backend')
        ");
        $insLead->execute([password_hash($pw, PASSWORD_DEFAULT)]);
        $leadId = (int)$pdo->lastInsertId();

        // Write lead credentials to ../credentials_staff.txt outside web root
        $staffCredFile = dirname(__DIR__, 2) . '/credentials_staff.txt';
        $leadLine = sprintf(
            "%-4d | %-20s | %-16s | %-16s | %-16s | %-8s\n",
            $leadId, 'Mubeen Fatima', 'mubeen.fatima', $pw, 'pms_lead', 'Active'
        );
        file_put_contents($staffCredFile, $leadLine, FILE_APPEND);
    } else {
        $leadId = (int)$existingLead['id'];
        $pdo->exec("UPDATE users SET role = 'pms_lead', department = 'backend', active = 1 WHERE id = {$leadId}");
    }

    return [
        'processing_staff_mapped' => ['minahil', 'sehrish', 'farwa', 'nimra', 'mujassam'],
        'pms_lead_id'             => $leadId,
        'admin_slot'              => 'empty (0/1)',
    ];
};
