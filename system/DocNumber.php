<?php
/**
 * Document numbers <PREFIX>-<BRANCH CODE>-<YEAR>-NNNNNN from a locked document_sequences row
 * (inside the caller's transaction; a rollback releases the number). Used by the purchasing documents;
 * older modules keep their own copy of the same logic.
 */
declare(strict_types=1);

final class DocNumber
{
    public static function next(int $branchId, string $prefix): string
    {
        $pdo = db();
        if (!$pdo->inTransaction()) {
            throw new LogicException('DocNumber::next() must run inside a transaction.');
        }
        $year = (int) date('Y');
        $pdo->prepare(
            'INSERT INTO document_sequences (branch_id, doc_type, year, last_no) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE last_no = last_no'
        )->execute([$branchId, $prefix, $year, 0]);
        $stmt = $pdo->prepare('SELECT last_no FROM document_sequences WHERE branch_id = ? AND doc_type = ? AND year = ? FOR UPDATE');
        $stmt->execute([$branchId, $prefix, $year]);
        $next = (int) $stmt->fetchColumn() + 1;
        $pdo->prepare('UPDATE document_sequences SET last_no = ? WHERE branch_id = ? AND doc_type = ? AND year = ?')
            ->execute([$next, $branchId, $prefix, $year]);
        $stmt = $pdo->prepare('SELECT code FROM branches WHERE id = ?');
        $stmt->execute([$branchId]);
        return sprintf('%s-%s-%04d-%06d', $prefix, (string) $stmt->fetchColumn(), $year, $next);
    }
}
