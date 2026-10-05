<?php
/**
 * Optional attachments (photos / scanned PDFs) on PO Internal, PO Outgoing and collection receipts (migration 016).
 *
 *   files   storage/attachments/<32 hex>.<ext> (storage/ is "Require all denied"); served only by pages/attachment.php
 *           after can view. JPG / PNG / WebP (content-checked, decodes as an image) or PDF (content-checked, %PDF-),
 *           max 5 MB, max 10 per document. The original name is kept for display / download only.
 *   view    whoever can view the document (PO: PurchaseOrders::canView(), order: any customer orders permission,
 *           collection: Collections::canView()) + branch access.
 *   upload  PO: purchasing.order + products.cost; order: customer_orders.manage / .deliver; collection:
 *           collections.manage. Working in the document's branch; not on a cancelled document.
 *   delete  the uploader on the day of the upload, or the document's approver (purchasing.approve /
 *           customer_orders.approve / collections.cancel); working in the branch. The row stays (deleted_at / by)
 *           and the file is removed. Upload + delete go to the audit log of the document's module.
 */
declare(strict_types=1);

final class Attachments
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_FILES = 10;
    public const MAX_SIDE  = 10000;
    public const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    public const ACCEPT = 'image/jpeg,image/png,image/webp,application/pdf';

    /** doc_type => [table, number column, draft label, audit module, labels]. Table names are code literals. */
    private const DOCS = [
        'purchase_order' => ['purchase_orders', 'po_no', 'Draft PO #', 'purchasing',
            ['Signed PO', 'Supplier quotation', 'Supplier invoice', 'Delivery receipt', 'Other']],
        'customer_order' => ['customer_orders', 'order_no', 'Draft Order #', 'customer_orders',
            ['Customer PO scan', 'Signed DR / IAR', 'Notice of award', 'Delivery photo', 'Other']],
        'collection'     => ['collections', 'collection_no', 'Collection #', 'collections',
            ['Check photo', 'Deposit slip', 'BIR 2307 / 2306', 'Official receipt', 'Other']],
    ];

    public static function dir(): string
    {
        return ROOT_PATH . '/storage/attachments';
    }

    /** @return string[] */
    public static function labels(string $docType): array
    {
        return self::DOCS[$docType][4] ?? [];
    }

    // ------------------------------------------------------------------
    // Access
    // ------------------------------------------------------------------

    public static function canView(string $docType): bool
    {
        return match ($docType) {
            'purchase_order' => PurchaseOrders::canView(),
            'customer_order' => Auth::canAny(...CustomerOrders::VIEW_PERMISSIONS),
            'collection'     => Collections::canView(),
            default          => false,
        };
    }

    private static function canUploadType(string $docType): bool
    {
        return match ($docType) {
            'purchase_order' => PurchaseOrders::canManage(),
            'customer_order' => Auth::canAny('customer_orders.manage', 'customer_orders.deliver'),
            'collection'     => Auth::can('collections.manage'),
            default          => false,
        };
    }

    private static function isApprover(string $docType): bool
    {
        return match ($docType) {
            'purchase_order' => Auth::can('purchasing.approve'),
            'customer_order' => Auth::can('customer_orders.approve'),
            'collection'     => Auth::can('collections.cancel'),
            default          => false,
        };
    }

    /** Can the user add files to this document right now (permission, branch, not cancelled)? */
    public static function canUpload(string $docType, array $doc): bool
    {
        return self::canUploadType($docType) && Branch::current() === (int) $doc['branch_id'] && $doc['status'] !== 'cancelled';
    }

    public static function canDelete(string $docType, array $doc, array $att): bool
    {
        if (Branch::current() !== (int) $doc['branch_id']) {
            return false;
        }
        $own = (int) $att['uploaded_by'] === (int) Auth::id() && substr((string) $att['uploaded_at'], 0, 10) === date('Y-m-d');
        return ($own && self::canUploadType($docType)) || self::isApprover($docType);
    }

    /** The document (id, branch_id, status, ref) after view + branch access checks; 404 otherwise. */
    public static function document(string $docType, int $docId, bool $lock = false): array
    {
        $def = self::DOCS[$docType] ?? throw new HttpException(404, 'Document not found.');
        if (!self::canView($docType)) {
            throw new HttpException(403, 'You do not have permission to view this document.');
        }
        [$table, $noCol, $draft] = $def;
        $stmt = db()->prepare("SELECT id, branch_id, status, {$noCol} AS doc_no FROM {$table} WHERE id = ?" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$docId]);
        $doc = $stmt->fetch() ?: throw new HttpException(404, 'Document not found.');
        Branch::assertAccess((int) $doc['branch_id']);
        $doc['ref'] = $doc['doc_no'] ?? ($draft . $doc['id']);
        return $doc;
    }

    /** Live attachments of a document (caller checked access). */
    public static function forDocument(string $docType, int $docId): array
    {
        $stmt = db()->prepare(
            'SELECT a.id, a.label, a.original_name, a.mime, a.size_bytes, a.uploaded_by, a.uploaded_at, u.full_name AS uploaded_by_name
               FROM document_attachments a JOIN users u ON u.id = a.uploaded_by
              WHERE a.doc_type = ? AND a.doc_id = ? AND a.deleted_at IS NULL ORDER BY a.id'
        );
        $stmt->execute([$docType, $docId]);
        return $stmt->fetchAll();
    }

    /** One live attachment + its document, for viewing (403 / 404 checks included). */
    public static function findForView(int $id): array
    {
        $stmt = db()->prepare('SELECT * FROM document_attachments WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $att = $stmt->fetch() ?: throw new HttpException(404, 'Attachment not found.');
        self::document((string) $att['doc_type'], (int) $att['doc_id']);
        $path = self::dir() . '/' . $att['filename'];
        if (!self::isValidName((string) $att['filename']) || !is_file($path)) {
            throw new HttpException(404, 'The file is missing.');
        }
        $att['path'] = $path;
        return $att;
    }

    // ------------------------------------------------------------------
    // Upload / delete
    // ------------------------------------------------------------------

    /** Validate + store an uploaded file for a document. @return int attachment id */
    public static function upload(string $docType, int $docId, mixed $file, string $label, int $userId): int
    {
        if (!isset(self::DOCS[$docType])) {
            throw new HttpException(404, 'Document not found.');
        }
        if (!self::canUploadType($docType)) {
            throw new HttpException(403, 'You do not have permission to add attachments to this document.');
        }
        if (!in_array($label, self::labels($docType), true)) {
            throw new HttpException(422, 'Choose what the file is.', ['errors' => ['label' => 'Choose what the file is.']]);
        }
        $doc = self::document($docType, $docId);
        CustomerOrders::assertWorkingIn((int) $doc['branch_id']);
        [$tmp, $mime, $size, $original] = self::check($file);

        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Attachment folder is missing: ' . $dir);
        }
        $name = bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
            throw new RuntimeException('Could not store the attachment in ' . $dir);
        }
        @chmod($dir . '/' . $name, 0640);

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $doc = self::document($docType, $docId, true); // row lock: the per-document limit holds under concurrency
            if ($doc['status'] === 'cancelled') {
                throw new HttpException(409, "{$doc['ref']} is cancelled: no more attachments.");
            }
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM document_attachments WHERE doc_type = ? AND doc_id = ? AND deleted_at IS NULL');
            $stmt->execute([$docType, $docId]);
            if ((int) $stmt->fetchColumn() >= self::MAX_FILES) {
                throw new HttpException(422, 'A document can have at most ' . self::MAX_FILES . ' attachments. Delete one first.');
            }
            $pdo->prepare(
                'INSERT INTO document_attachments (doc_type, doc_id, branch_id, label, filename, original_name, mime, size_bytes, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$docType, $docId, (int) $doc['branch_id'], $label, $name, $original, $mime, $size, $userId]);
            $id = (int) $pdo->lastInsertId();
            Audit::record(self::DOCS[$docType][3], 'attachment_add', $docType, $docId, (string) $doc['ref'], null,
                ['label' => $label, 'file' => $original], (int) $doc['branch_id']);
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @unlink($dir . '/' . $name);
            throw $e;
        }
    }

    public static function delete(int $id, int $userId): array
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM document_attachments WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            $stmt->execute([$id]);
            $att = $stmt->fetch() ?: throw new HttpException(404, 'Attachment not found.');
            $docType = (string) $att['doc_type'];
            $doc = self::document($docType, (int) $att['doc_id']);
            CustomerOrders::assertWorkingIn((int) $doc['branch_id']);
            if (!self::canDelete($docType, $doc, $att)) {
                throw new HttpException(403, 'Only the person who uploaded it (on the same day) or an approver can delete this attachment.');
            }
            $pdo->prepare('UPDATE document_attachments SET deleted_at = NOW(), deleted_by = ? WHERE id = ?')->execute([$userId, $id]);
            Audit::record(self::DOCS[$docType][3], 'attachment_delete', $docType, (int) $att['doc_id'], (string) $doc['ref'],
                ['label' => $att['label'], 'file' => $att['original_name']], null, (int) $doc['branch_id']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        if (self::isValidName((string) $att['filename'])) {
            @unlink(self::dir() . '/' . $att['filename']);
        }
        return ['doc_type' => $docType, 'doc_id' => (int) $att['doc_id'], 'label' => (string) $att['label']];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array{0: string, 1: string, 2: int, 3: string} tmp path, mime, size, cleaned original name */
    private static function check(mixed $file): array
    {
        $error = is_array($file) ? ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if (is_array($error)) {
            throw new HttpException(422, 'Upload one file at a time.');
        }
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new HttpException(422, 'Choose a photo or PDF to attach.', ['errors' => ['file' => 'Choose a file.']]);
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new HttpException(422, 'The file is too large. Maximum size is 5 MB.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            throw new HttpException(422, 'The file could not be uploaded. Please try again.');
        }
        $size = (int) filesize($tmp);
        if ($size < 1) {
            throw new HttpException(422, 'The file is empty.');
        }
        if ($size > self::MAX_BYTES) {
            throw new HttpException(422, 'The file is too large. Maximum size is 5 MB.');
        }
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::TYPES[$mime])) {
            throw new HttpException(422, 'Only photos (JPG, PNG, WebP) or PDF files can be attached.');
        }
        if ($mime === 'application/pdf') {
            $head = (string) file_get_contents($tmp, false, null, 0, 5);
            if ($head !== '%PDF-') {
                throw new HttpException(422, 'That file is not a valid PDF.');
            }
        } else {
            $info = @getimagesize($tmp);
            if ($info === false || ($info['mime'] ?? '') !== $mime || $info[0] < 1 || $info[1] < 1
                || $info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE) {
                throw new HttpException(422, 'That file is not a valid image (max ' . self::MAX_SIDE . ' pixels per side).');
            }
        }
        $original = basename(str_replace('\\', '/', (string) ($file['name'] ?? '')));
        $original = trim((string) preg_replace('/[\x00-\x1F\x7F"<>|:*?\/\\\\]+/u', '_', $original));
        if ($original === '' || !mb_check_encoding($original, 'UTF-8')) {
            $original = 'attachment.' . self::TYPES[$mime];
        }
        return [$tmp, $mime, $size, mb_substr($original, 0, 150)];
    }

    private static function isValidName(string $name): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|pdf)$/', $name);
    }
}
