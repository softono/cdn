<?php

/**
 * ListObjects (V1 + V2), with delimiter/CommonPrefixes support and honest
 * pagination (IsTruncated is only ever true when there is verifiably more
 * to return).
 */
class S3Listing
{
    /**
     * Fetches up to $maxKeys distinct entries (Contents + CommonPrefixes)
     * starting after $afterKey, honoring $prefix/$delimiter. Returns
     * [contents, commonPrefixes, isTruncated, lastKey]. lastKey is the key
     * of the last consumed row and doubles as the next page's cursor.
     */
    private static function collectListing(string $bucketId, string $prefix, string $delimiter, string $afterKey, int $maxKeys): array
    {
        $contents = [];
        $commonPrefixes = [];
        $seenPrefixes = [];
        $cursor = $afterKey;
        $lastKey = $afterKey;
        $isTruncated = false;
        $exhausted = false;

        $escapedPrefix = strtr($prefix, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
        $batchSize = max($maxKeys * 2, 200);
        $maxIterations = 20;

        for ($i = 0; $i < $maxIterations; $i++) {
            $sql = "SELECT `object_key`, `size`, `checksum`, `updated_at` FROM `objects` WHERE `bucket_id` = ?";
            $params = [$bucketId];

            if ($prefix !== '') {
                $sql .= " AND `object_key` LIKE ? ESCAPE '\\\\'";
                $params[] = $escapedPrefix . '%';
            }
            if ($cursor !== '') {
                $sql .= " AND `object_key` > ?";
                $params[] = $cursor;
            }
            $sql .= " ORDER BY `object_key` ASC LIMIT " . (int)$batchSize;

            $rows = DB::fetchAll($sql, $params);
            if (empty($rows)) {
                $exhausted = true;
                break;
            }

            foreach ($rows as $row) {
                $key = $row['object_key'];

                if ($delimiter !== '') {
                    $rest = substr($key, strlen($prefix));
                    $pos = strpos($rest, $delimiter);
                    if ($pos !== false) {
                        $cp = $prefix . substr($rest, 0, $pos + strlen($delimiter));
                        if (!isset($seenPrefixes[$cp])) {
                            if ((count($contents) + count($commonPrefixes)) >= $maxKeys) {
                                $isTruncated = true;
                                break 2;
                            }
                            $seenPrefixes[$cp] = true;
                            $commonPrefixes[] = $cp;
                        }
                        $lastKey = $key;
                        continue;
                    }
                }

                if ((count($contents) + count($commonPrefixes)) >= $maxKeys) {
                    $isTruncated = true;
                    break 2;
                }
                $contents[] = $row;
                $lastKey = $key;
            }

            $cursor = $lastKey;

            if (count($rows) < $batchSize) {
                $exhausted = true;
                break;
            }
        }

        // Hit the iteration cap without proving the bucket is exhausted:
        // never claim completeness we haven't verified.
        if (!$exhausted && !$isTruncated) {
            $isTruncated = true;
        }

        return [$contents, $commonPrefixes, $isTruncated, $lastKey];
    }

    public static function listObjects(array $bucket, array $query): void
    {
        $prefix = (string)($query['prefix'] ?? '');
        $delimiter = (string)($query['delimiter'] ?? '');
        $maxKeys = isset($query['max-keys']) ? (int)$query['max-keys'] : 1000;
        if ($maxKeys <= 0 || $maxKeys > 1000) {
            $maxKeys = 1000;
        }

        $isV2 = (string)($query['list-type'] ?? '1') === '2';
        $marker = (string)($query['marker'] ?? '');
        $startAfter = (string)($query['start-after'] ?? '');
        $continuationToken = (string)($query['continuation-token'] ?? '');

        if ($isV2) {
            $afterKey = $continuationToken !== '' ? (base64_decode($continuationToken, true) ?: '') : $startAfter;
        } else {
            $afterKey = $marker;
        }

        [$contents, $commonPrefixes, $isTruncated, $lastKey] = self::collectListing(
            $bucket['id'],
            $prefix,
            $delimiter,
            $afterKey,
            $maxKeys
        );

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
. '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
    . '<Name>' . htmlspecialchars($bucket['name'], ENT_XML1, 'UTF-8') . '</Name>'
    . '<Prefix>' . htmlspecialchars($prefix, ENT_XML1, 'UTF-8') . '</Prefix>';

    if ($delimiter !== '') {
    $xml .= '<Delimiter>' . htmlspecialchars($delimiter, ENT_XML1, 'UTF-8') . '</Delimiter>';
    }

    if ($isV2) {
    $xml .= '<KeyCount>' . (count($contents) + count($commonPrefixes)) . '</KeyCount>'
    . '<MaxKeys>' . $maxKeys . '</MaxKeys>'
    . '<IsTruncated>' . ($isTruncated ? 'true' : 'false') . '</IsTruncated>';
    if ($startAfter !== '') {
    $xml .= '<StartAfter>' . htmlspecialchars($startAfter, ENT_XML1, 'UTF-8') . '</StartAfter>';
    }
    if ($continuationToken !== '') {
    $xml .= '<ContinuationToken>' . htmlspecialchars($continuationToken, ENT_XML1, 'UTF-8') . '</ContinuationToken>';
    }
    if ($isTruncated) {
    $xml .= '<NextContinuationToken>' . base64_encode($lastKey) . '</NextContinuationToken>';
    }
    } else {
    $xml .= '<Marker>' . htmlspecialchars($marker, ENT_XML1, 'UTF-8') . '</Marker>'
    . '<MaxKeys>' . $maxKeys . '</MaxKeys>'
    . '<IsTruncated>' . ($isTruncated ? 'true' : 'false') . '</IsTruncated>';
    if ($isTruncated) {
    $xml .= '<NextMarker>' . htmlspecialchars($lastKey, ENT_XML1, 'UTF-8') . '</NextMarker>';
    }
    }

    foreach ($contents as $obj) {
    $lastMod = date('c', strtotime($obj['updated_at']));
    $etag = $obj['checksum'] ? '"' . $obj['checksum'] . '"' : '';
    $xml .= '<Contents>'
        . '<Key>' . htmlspecialchars($obj['object_key'], ENT_XML1, 'UTF-8') . '</Key>'
        . '<LastModified>' . $lastMod . '</LastModified>'
        . '<ETag>' . htmlspecialchars($etag, ENT_XML1, 'UTF-8') . '</ETag>'
        . '<Size>' . (int)$obj['size'] . '</Size>'
        . '<StorageClass>STANDARD</StorageClass>'
        . '</Contents>';
    }

    foreach ($commonPrefixes as $cp) {
    $xml .= '<CommonPrefixes>
        <Prefix>' . htmlspecialchars($cp, ENT_XML1, 'UTF-8') . '</Prefix>
    </CommonPrefixes>';
    }

    $xml .= '</ListBucketResult>';
Response::sendXml(200, $xml);
}
}