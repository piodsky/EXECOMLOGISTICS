<?php
/**
 * Serve one attachment (GET pages/attachment.php?id=N[&download=1]) after Attachments::findForView() checked that the
 * user may view its document. Images and PDFs open in the browser; download=1 saves the file under its original name.
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';
Auth::requireLogin();

$id  = input_int($_GET, 'id', 1) ?? throw new HttpException(404, 'Attachment not found.');
$att = Attachments::findForView($id);

$name     = (string) $att['original_name'];
$ascii    = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?: 'attachment';
$download = isset($_GET['download']);

header('Content-Type: ' . $att['mime']);
header('Content-Length: ' . (string) filesize($att['path']));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
// Cache-Control stays no-store (send_security_headers): a deleted file or a lost permission takes effect at once.
if ($att['mime'] === 'application/pdf') {
    // The browser's PDF viewer is blocked by object-src 'none'; a PDF has no page scripts of ours to protect.
    header("Content-Security-Policy: default-src 'none'; object-src 'self'; style-src 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'");
} else {
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'none'");
}
readfile($att['path']);
