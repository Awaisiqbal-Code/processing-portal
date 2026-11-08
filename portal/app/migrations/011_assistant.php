<?php
declare(strict_types=1);

/**
 * Migration 011 — PMS Assistant (Gemini) conversation log.
 * Each person's own chat history (used as context and kept for audit: which lookups were made).
 */
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS assistant_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        role TEXT NOT NULL,                 -- user | assistant
        content TEXT NOT NULL,
        tools_json TEXT NULL,               -- lookups the assistant ran for this answer
        created_at TEXT NOT NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_assistant_user ON assistant_messages(user_id, id)');
    echo "  -> assistant_messages ready\n";
};
