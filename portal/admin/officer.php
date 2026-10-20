<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$u = require_role('admin');

$oid = get('id');
if ($oid !== '') {
    redirect('admin/officers.php?id=' . rawurlencode($oid));
} else {
    redirect('admin/officers.php');
}
