<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/stages.php';

/**
 * Ensures drive_files_json column exists on students table.
 */
function ensure_drive_schema(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        q("SELECT drive_files_json FROM students LIMIT 1");
    } catch (Throwable $e) {
        try {
            db()->exec("ALTER TABLE students ADD COLUMN drive_files_json TEXT NULL");
        } catch (Throwable $e2) {}
    }
}

/**
 * Parse Google Drive folder HTML and return list of items:
 * [['id' => ..., 'name' => ..., 'raw_title' => ..., 'is_folder' => bool, 'size' => ...], ...]
 */
function parse_drive_folder_html(string $html): array {
    if ($html === '') return [];
    $items = [];
    if (!preg_match_all('/<tr[^>]*data-id="([^"]+)"[^>]*>(.*?)<\/tr>/si', $html, $matches, PREG_SET_ORDER)) {
        return [];
    }
    foreach ($matches as $m) {
        $itemId = trim($m[1]);
        $body = $m[2];
        $tooltips = [];
        if (preg_match_all('/data-tooltip="([^"]+)"/i', $body, $tm)) {
            foreach ($tm[1] as $t) {
                $tooltips[] = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        $plain = strip_tags(str_replace(['</td>', '</div>', '</span>'], "\n", $body));
        $lines = array_values(array_filter(array_map(
            fn($l) => trim(html_entity_decode($l, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            explode("\n", $plain)
        ), fn($l) => $l !== ''));

        $rawTitle = $tooltips[0] ?? ($lines[0] ?? '');
        $hasFileExt = (bool)preg_match('/\.(pdf|jpg|jpeg|png|doc|docx|mp4|mkv|zip|rar)\b/i', $rawTitle);
        $isFolder = (stripos($rawTitle, 'Shared folder') !== false)
            || (stripos($rawTitle, 'folder') !== false && !$hasFileExt);

        $cleanName = preg_replace('/\s+Shared folder$/i', '', $rawTitle);
        $cleanName = preg_replace('/\s+(PDF|Image|Video|Word|Audio|Compressed archive)$/i', '', (string)$cleanName);
        $cleanName = trim((string)$cleanName);
        if ($cleanName === '' && !empty($lines[0])) {
            $cleanName = $lines[0];
        }

        $sizeStr = '';
        foreach ($tooltips as $t) {
            if (stripos($t, 'Size:') !== false) {
                $parts = explode("\n", $t);
                $sizeStr = trim(str_ireplace('Size:', '', $parts[0]));
                break;
            }
        }

        $items[] = [
            'id'        => $itemId,
            'name'      => $cleanName,
            'raw_title' => $rawTitle,
            'is_folder' => $isFolder,
            'size'      => $sizeStr,
        ];
    }
    return $items;
}

/**
 * Fetch multiple Google Drive folder URLs in parallel using curl_multi.
 * @param array<string,string> $folderIds Map of key => folderId
 * @return array<string,string> Map of key => raw HTML
 */
function fetch_drive_folders_multi(array $folderIds): array {
    if (!$folderIds) return [];
    $mh = curl_multi_init();
    $handles = [];
    foreach ($folderIds as $k => $fid) {
        $url = 'https://drive.google.com/drive/folders/' . rawurlencode($fid);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$k] = $ch;
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 0.2);
        }
    } while ($running > 0);

    $results = [];
    foreach ($handles as $k => $ch) {
        $results[$k] = (string)curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

/**
 * Classify a specific file into one of DOCS[0..11] indices, or null if extra file.
 * DOCS: [0:Passport, 1:CNIC, 2:Matric, 3:Inter, 4:Bachelor, 5:IELTS/PTE, 6:CV, 7:Bank statement, 8:Tax docs, 9:Offer letter, 10:Enrollment/CAS, 11:Visa decision]
 */
function classify_drive_file_to_doc_index(string $fileName, string $subfolder = ''): ?int {
    $fn = strtolower($fileName);
    $sf = strtolower($subfolder);
    $full = $sf . ' ' . $fn;

    // Consent forms are NOT part of standard 0..11 checklist - they belong in additional files & consent stage
    if (str_contains($full, 'consent')) {
        return null;
    }

    // Video / Zoom / Meet recordings are meeting assets, not documents
    if (str_contains($full, 'zoom') || str_contains($full, 'meet') || str_ends_with($fn, '.mp4') || str_contains($full, 'video')) {
        return null;
    }

    // 0: Passport
    if (str_contains($fn, 'passport')) return 0;

    // 1: CNIC / B-form
    if (preg_match('/\b(cnic|nic|id[\s_\-]?card|b[\s_\-]?form|identity|frc)\b/i', $fn)) {
        return 1;
    }

    // 2: Matric certificate & transcript
    if ((preg_match('/\b(ssc|matric|matriculation|10th)\b/i', $fn) || str_contains($fn, 'secondary school') || str_contains($fn, 'o level') || str_contains($fn, 'o-level'))
        && !str_contains($fn, 'hssc') && !str_contains($fn, 'higher secondary') && !str_contains($fn, 'transscript')) {
        return 2;
    }

    // 3: Intermediate certificate & transcript
    foreach (['hssc', 'intermediate', 'inter ', 'inter.', 'fsc', 'higher secondary', 'higher marksheet', 'higher education cert', '12th', 'a level', 'a-level', 'dae'] as $k) {
        if (str_contains($fn, $k)) return 3;
    }

    // 4: Bachelor degree & transcript
    foreach (['bachelor', 'degree', 'transcript', 'bs ', 'bs-', 'bsc', 'bba', 'mphil', 'master', 'ms ', 'provisional', 'graduation', 'marksheet'] as $k) {
        if (str_contains($fn, $k)) return 4;
    }

    // 5: IELTS / PTE / Language cert
    foreach (['ielts', 'moi', 'english', 'language', 'proficiency', 'pte', 'toefl', 'duolingo', 'etrf'] as $k) {
        if (str_contains($fn, $k)) return 5;
    }

    // 6: CV
    if ($sf === 'cv' || str_contains($fn, 'cv') || str_contains($fn, 'resume') || str_contains($fn, 'curriculum')) {
        return 6;
    }

    // 7: Bank statement
    foreach (['bank', 'bank statement', 'account statement', 'account maintenance', 'financial statement', 'bank cert', 'closing statement'] as $k) {
        if (str_contains($fn, $k) && !str_contains($fn, 'performance statement')) return 7;
    }

    // 8: Tax documents
    foreach (['tax', 'fbr', 'ntn', 'wealth', 'income tax', 'tax return'] as $k) {
        if (str_contains($fn, $k)) return 8;
    }

    // 9: University Admission / Scholarship Offer letter (ONLY official foreign university admission offer)
    foreach (['university offer letter', 'admission offer', 'acceptance letter', 'scholarship offer', 'unconditional offer', 'conditional offer', 'letter of acceptance', 'letter of offer', 'eligibility letter'] as $k) {
        if (str_contains($fn, $k) && !str_contains($fn, 'intern') && !str_contains($fn, 'virtual force') && !str_contains($fn, 'training')) {
            return 9;
        }
    }

    // 10: Enrollment / CAS
    foreach (['cas', 'enrollment', 'enrolment', 'confirmation of acceptance', 'summary sheet'] as $k) {
        if (str_contains($fn, $k)) return 10;
    }

    // 11: Visa decision letter
    foreach (['visa decision', 'visa grant', 'visa refusal', 'visa letter', 'visa sticker', 'embassy outcome'] as $k) {
        if (str_contains($fn, $k)) return 11;
    }

    return null;
}

/**
 * Resolves and caches a student's Google Drive subfolders, specific document files, and recorded meeting link.
 */
function resolve_student_drive_assets(int $studentId, bool $force = false): array {
    ensure_drive_schema();
    $st = q("SELECT id, name, drive_link, meeting_link, drive_files_json FROM students WHERE id = ?", [$studentId])->fetch();
    if (!$st) return ['meeting_link' => '', 'files' => [], 'doc_map' => []];

    if (!$force && !empty($st['drive_files_json'])) {
        $decoded = json_decode((string)$st['drive_files_json'], true);
        if (is_array($decoded) && isset($decoded['files'])) {
            return $decoded;
        }
    }

    $driveLink = (string)($st['drive_link'] ?? '');
    $oldMeeting = (string)($st['meeting_link'] ?? '');
    $meetingUrl = (stripos($oldMeeting, 'zoom.us') !== false) ? $oldMeeting : '';

    if (!preg_match('/\/folders\/([a-zA-Z0-9_\-]+)/', $driveLink, $m)) {
        return ['meeting_link' => $meetingUrl, 'files' => [], 'doc_map' => []];
    }

    $rootFolderId = $m[1];
    $rootHtmlMap = fetch_drive_folders_multi(['root' => $rootFolderId]);
    $rootItems = parse_drive_folder_html($rootHtmlMap['root'] ?? '');

    $allFiles = [];
    $subfoldersToFetch = [];

    foreach ($rootItems as $it) {
        if ($it['is_folder']) {
            $subfoldersToFetch[$it['id']] = $it['name'];
            $ln = strtolower($it['name']);
            if (str_contains($ln, 'zoom') || str_contains($ln, 'meet') || str_contains($ln, 'record')) {
                if ($meetingUrl === '') {
                    $meetingUrl = 'https://drive.google.com/drive/folders/' . $it['id'];
                }
            }
        } else {
            $ln = strtolower($it['name'] . ' ' . $it['raw_title']);
            $isDocExt = (bool)preg_match('/\.(pdf|jpg|jpeg|png|doc|docx)\b/i', $it['name']);
            if (!$isDocExt && (str_contains($ln, 'zoom') || str_contains($ln, 'meet') || str_contains($ln, '.mp4') || str_contains($ln, '.m4a') || str_contains($ln, 'video'))) {
                $meetingUrl = 'https://drive.google.com/file/d/' . $it['id'] . '/view';
                $it['subfolder'] = '';
                $it['is_meeting'] = true;
                $allFiles[] = $it;
            } else {
                $it['subfolder'] = '';
                $allFiles[] = $it;
            }
        }
    }

    if ($subfoldersToFetch) {
        $subIds = [];
        foreach ($subfoldersToFetch as $sfId => $sfName) {
            $subIds[$sfId] = $sfId;
        }
        $subHtmls = fetch_drive_folders_multi($subIds);
        foreach ($subHtmls as $sfId => $sfHtml) {
            $sfName = $subfoldersToFetch[$sfId] ?? '';
            $sfLower = strtolower($sfName);
            $isMeetingFolder = str_contains($sfLower, 'zoom') || str_contains($sfLower, 'meet') || str_contains($sfLower, 'record');
            $subItems = parse_drive_folder_html($sfHtml);
            foreach ($subItems as $subIt) {
                if ($subIt['is_folder']) continue;
                $subIt['subfolder'] = $sfName;
                $rawLower = strtolower($subIt['name'] . ' ' . $subIt['raw_title']);
                $isDocExt = (bool)preg_match('/\.(pdf|jpg|jpeg|png|doc|docx)\b/i', $subIt['name']) || str_contains($rawLower, ' pdf');
                if (!$isDocExt && ($isMeetingFolder || str_contains($rawLower, 'video') || str_ends_with(strtolower($subIt['name']), '.mp4') || str_ends_with(strtolower($subIt['name']), '.m4a'))) {
                    $meetingUrl = 'https://drive.google.com/file/d/' . $subIt['id'] . '/view';
                    $subIt['is_meeting'] = true;
                    $allFiles[] = $subIt;
                } else {
                    $allFiles[] = $subIt;
                }
            }
        }
    }

    $docMap = [];
    foreach ($allFiles as $f) {
        if (!empty($f['is_meeting'])) continue;
        $idx = classify_drive_file_to_doc_index($f['name'], $f['subfolder'] ?? '');
        if ($idx !== null) {
            if (!isset($docMap[$idx]) || ($idx === 6 && (str_contains(strtolower($f['subfolder'] ?? ''), 'cv') || str_contains(strtolower($f['name']), 'final')))) {
                $docMap[$idx] = $f;
            }
        }
    }

    // Persist mapped documents in student_documents
    $existingDocs = [];
    foreach (q("SELECT * FROM student_documents WHERE student_id = ?", [$studentId]) as $dRow) {
        $existingDocs[(int)$dRow['doc_index']] = $dRow;
    }

    // Process all 12 standard documents
    foreach (DOCS as $docIdx => $docName) {
        $curDoc = $existingDocs[$docIdx] ?? null;
        $curPath = (string)($curDoc['file_path'] ?? '');
        $isLocal = ($curPath !== '' && !str_starts_with($curPath, 'gdrive:'));

        if ($isLocal) {
            $st = in_array($curDoc['status'] ?? '', ['Received', 'Verified'], true) ? $curDoc['status'] : 'Received';
            q("UPDATE student_documents SET status = ? WHERE student_id = ? AND doc_index = ?", [$st, $studentId, $docIdx]);
            continue;
        }

        if (isset($docMap[$docIdx])) {
            $fInfo = $docMap[$docIdx];
            $gPath = 'gdrive:' . $fInfo['id'];
            $fName = $fInfo['name'];
            if (!preg_match('/\.(pdf|jpg|jpeg|png|doc|docx)$/i', $fName)) {
                $fName .= '.pdf';
            }
            if ($curDoc) {
                q("UPDATE student_documents SET status = 'Received', file_path = ?, file_name = ?, mime_type = 'application/pdf', updated_at = ? WHERE student_id = ? AND doc_index = ?",
                  [$gPath, $fName, now(), $studentId, $docIdx]);
            } else {
                q("INSERT INTO student_documents (student_id, doc_index, status, file_path, file_name, mime_type, uploaded_at, updated_at) VALUES (?, ?, 'Received', ?, ?, 'application/pdf', ?, ?)",
                  [$studentId, $docIdx, $gPath, $fName, now(), now()]);
            }
        } else {
            // No file available -> Must be Pending!
            $clearPath = str_starts_with($curPath, 'gdrive:') ? null : ($curPath ?: null);
            $clearName = str_starts_with($curPath, 'gdrive:') ? null : ($curDoc['file_name'] ?? null);
            if ($curDoc) {
                q("UPDATE student_documents SET status = 'Pending', file_path = ?, file_name = ?, updated_at = ? WHERE student_id = ? AND doc_index = ?",
                  [$clearPath, $clearName, now(), $studentId, $docIdx]);
            } else {
                q("INSERT INTO student_documents (student_id, doc_index, status, file_path, file_name, uploaded_at, updated_at) VALUES (?, ?, 'Pending', NULL, NULL, ?, ?)",
                  [$studentId, $docIdx, now(), now()]);
            }
        }
    }

    // Sync student stage 'offer' with actual availability of offer letter
    $hasOfferDoc = false;
    $offerDocRow = q("SELECT file_path FROM student_documents WHERE student_id = ? AND doc_index = 9", [$studentId])->fetch();
    if (!empty($offerDocRow['file_path'])) {
        $hasOfferDoc = true;
    }
    q("UPDATE students SET offer = ? WHERE id = ?", [$hasOfferDoc ? 'Yes' : 'No', $studentId]);

    $payload = [
        'meeting_link' => $meetingUrl,
        'files'        => $allFiles,
        'doc_map'      => $docMap,
        'synced_at'    => now(),
    ];

    q("UPDATE students SET meeting_link = ?, drive_files_json = ? WHERE id = ?", [
        $meetingUrl !== '' ? $meetingUrl : null,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $studentId
    ]);

    return $payload;
}

/**
 * Streams or downloads a specific file directly from Google Drive by its file ID.
 */
function serve_google_drive_file(string $driveFileId, string $fallbackFileName, bool $forceDownload = false): bool {
    $dlUrl = 'https://drive.google.com/uc?export=download&id=' . rawurlencode($driveFileId);
    $ch = curl_init($dlUrl);
    $headers = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_HEADERFUNCTION => function ($curl, $headerLine) use (&$headers) {
            $len = strlen($headerLine);
            $parts = explode(':', $headerLine, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $len;
        }
    ]);

    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false || $httpCode !== 200 || strlen((string)$body) < 100) {
        // Fallback redirect directly to Google Drive view/download URL
        $target = $forceDownload
            ? 'https://drive.google.com/uc?export=download&id=' . rawurlencode($driveFileId)
            : 'https://drive.google.com/file/d/' . rawurlencode($driveFileId) . '/view';
        if (!headers_sent()) {
            header('Location: ' . $target);
        } else {
            echo '<script>window.location.replace(' . json_encode($target) . ');</script>';
        }
        exit;
    }

    // If Google Drive returned an HTML virus-scan warning page (for large files like videos), redirect to Google Drive directly
    if (stripos($contentType, 'text/html') !== false && !str_starts_with((string)$body, '%PDF')) {
        $target = $forceDownload
            ? 'https://drive.google.com/uc?export=download&id=' . rawurlencode($driveFileId)
            : 'https://drive.google.com/file/d/' . rawurlencode($driveFileId) . '/view';
        if (!headers_sent()) {
            header('Location: ' . $target);
        } else {
            echo '<script>window.location.replace(' . json_encode($target) . ');</script>';
        }
        exit;
    }

    // Determine filename from Content-Disposition if available
    $fileName = $fallbackFileName;
    if (!empty($headers['content-disposition'])) {
        if (preg_match('/filename\*=UTF-8\'\'([^;]+)/i', $headers['content-disposition'], $m)) {
            $fileName = rawurldecode($m[1]);
        } elseif (preg_match('/filename="([^"]+)"/i', $headers['content-disposition'], $m)) {
            $fileName = $m[1];
        }
    }
    $safeName = preg_replace('/[^a-zA-Z0-9_\-\.\(\) ]/', '_', $fileName);
    if ($safeName === '') $safeName = 'document.pdf';

    // Determine proper MIME type
    $mime = 'application/pdf';
    if (str_starts_with((string)$body, '%PDF')) {
        $mime = 'application/pdf';
        if (!preg_match('/\.pdf$/i', $safeName)) $safeName .= '.pdf';
    } elseif (preg_match('/\.(jpg|jpeg)$/i', $safeName)) {
        $mime = 'image/jpeg';
    } elseif (preg_match('/\.png$/i', $safeName)) {
        $mime = 'image/png';
    } elseif (!empty($contentType) && stripos($contentType, 'octet-stream') === false) {
        $mime = $contentType;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $disposition = $forceDownload ? 'attachment' : 'inline';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');
    header('Content-Length: ' . strlen((string)$body));
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    echo $body;
    exit;
}
