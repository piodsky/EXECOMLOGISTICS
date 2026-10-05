<?php
/**
 * POST pages/attachments.php: action = upload (doc_type, doc_id, label, file) or delete (id); back to the document
 * page (return, validated by safe_return()). Rules live in Attachments.
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
Auth::requireLogin();
if (!is_post()) {
    throw new HttpException(405, 'Use the Attachments card on the document.');
}
Csrf::verifyRequest();

$returnTo = safe_return($_POST['return'] ?? null, 'dashboard.php');
$action   = input_string($_POST, 'action', 10);
$uid      = (int) Auth::id();

try {
    if ($action === 'upload') {
        $docType = input_string($_POST, 'doc_type', 20);
        $docId   = input_int($_POST, 'doc_id', 1) ?? throw new HttpException(404, 'Document not found.');
        Attachments::upload($docType, $docId, $_FILES['file'] ?? null, input_string($_POST, 'label', 40), $uid);
        flash('success', 'Attachment added.');
    } elseif ($action === 'delete') {
        $res = Attachments::delete(input_int($_POST, 'id', 1) ?? throw new HttpException(404, 'Attachment not found.'), $uid);
        flash('success', "Attachment deleted ({$res['label']}).");
    } else {
        throw new HttpException(400, 'Unknown action.');
    }
} catch (HttpException $e) {
    if ($e->status !== 422 && $e->status !== 409) {
        throw $e;
    }
    flash('error', $e->getMessage());
}
redirect('pages/' . $returnTo . '#attachments');
