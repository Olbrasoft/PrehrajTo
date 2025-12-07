<?php
/**
 * Prehraj.to Proxy - extrahuje video URL a vyhledává filmy z české IP
 * Určeno pro tumarsrobot.unas.cz (Webzdarma.cz)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Determine action: search, stream, validate or get video
$action = isset($_GET['action']) ? $_GET['action'] : 'video';

if ($action === 'search') {
    handleSearch();
} elseif ($action === 'stream') {
    handleStream();
} elseif ($action === 'validate') {
    handleValidate();
} else {
    handleVideo();
}

/**
 * Handle search request - returns list of movies
 */
function handleSearch() {
    $query = isset($_GET['q']) ? trim($_GET['q']) : '';
    
    if (empty($query)) {
        echo json_encode([
            'success' => false,
            'error' => 'Missing search query (q parameter)',
            'usage' => 'index.php?action=search&q=matrix'
        ]);
        exit;
    }
    
    // URL encode the query
    $searchUrl = 'https://prehraj.to/hledej/' . rawurlencode($query);
    
    // Fetch search results
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $searchUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo json_encode([
            'success' => false,
            'error' => 'Curl error: ' . $error
        ]);
        exit;
    }
    
    if ($httpCode !== 200) {
        echo json_encode([
            'success' => false,
            'error' => 'HTTP error: ' . $httpCode
        ]);
        exit;
    }
    
    // Parse movie results
    $movies = [];
    
    // Find all video links with class="video" - this is the main pattern on prehraj.to
    // Pattern: <a class="video..." href="/movie-name/id" title="Movie Title"
    if (preg_match_all('/<a[^>]*class="[^"]*video[^"]*"[^>]*href="(\/[a-z0-9+-]+\/[a-z0-9]+)"[^>]*title="([^"]+)"[^>]*>/i', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $url = $match[1];
            $title = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
            
            // Skip duplicates
            $exists = false;
            foreach ($movies as $m) {
                if ($m['url'] === $url) {
                    $exists = true;
                    break;
                }
            }
            if ($exists) continue;
            
            // Extract thumbnail - look for img near this URL
            $thumbnail = '';
            $urlEscaped = preg_quote($url, '/');
            if (preg_match('/href="' . $urlEscaped . '".*?<img[^>]*src="([^"]+thumb[^"]*\.jpg)"/s', $html, $thumbMatch)) {
                $thumbnail = $thumbMatch[1];
            }
            
            // Extract year from URL or title
            $year = '';
            if (preg_match('/[-(](\d{4})[)-]/', $title, $yearMatch)) {
                $year = $yearMatch[1];
            } elseif (preg_match('/-(\d{4})[-\/]/', $url, $yearMatch)) {
                $year = $yearMatch[1];
            }
            
            $movies[] = [
                'url' => 'https://prehraj.to' . $url,
                'title' => $title,
                'thumbnail' => $thumbnail,
                'year' => $year
            ];
        }
    }
    
    // Alternative pattern - find thumbnails first and extract URLs
    if (empty($movies)) {
        // Look for video containers with data-video-id
        if (preg_match_all('/data-video-id="[^"]*".*?href="(\/[^"]+)"[^>]*title="([^"]+)".*?src="([^"]+\.jpg)"/s', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $movies[] = [
                    'url' => 'https://prehraj.to' . $match[1],
                    'title' => html_entity_decode($match[2], ENT_QUOTES, 'UTF-8'),
                    'thumbnail' => $match[3],
                    'year' => ''
                ];
            }
        }
    }
    
    // Limit results
    $movies = array_slice($movies, 0, 30);
    
    echo json_encode([
        'success' => true,
        'query' => $query,
        'count' => count($movies),
        'movies' => $movies
    ]);
}

/**
 * Handle video streaming - proxy video content from CDN
 */
function handleStream() {
    $url = isset($_GET['url']) ? $_GET['url'] : '';
    
    if (empty($url)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Missing url parameter']);
        exit;
    }
    
    // Validate URL is from CDN
    if (strpos($url, 'premiumcdn.net') === false && strpos($url, 'cdn') === false) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'URL must be from CDN']);
        exit;
    }
    
    // Get Range header if present (for video seeking)
    $range = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : null;
    
    // Setup curl for streaming
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 0); // No timeout for streaming
    curl_setopt($ch, CURLOPT_BUFFERSIZE, 8192);
    
    // Forward Range header
    if ($range) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Range: ' . $range]);
    }
    
    // First, get headers to determine content type and length
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    $headerResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    
    if ($httpCode >= 400) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'CDN returned HTTP ' . $httpCode]);
        exit;
    }
    
    // Set appropriate headers
    header('Content-Type: ' . ($contentType ?: 'video/mp4'));
    header('Accept-Ranges: bytes');
    header('Access-Control-Allow-Origin: *');
    
    if ($contentLength > 0) {
        header('Content-Length: ' . $contentLength);
    }
    
    if ($httpCode === 206) {
        http_response_code(206);
    }
    
    // Now stream the actual content
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_NOBODY, false);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
        echo $data;
        flush();
        return strlen($data);
    });
    
    curl_exec($ch);
    curl_close($ch);
    exit;
}

/**
 * Handle video validation - check if video URL works (HEAD request)
 * Returns: { "valid": true/false, "status": httpCode }
 */
function handleValidate() {
    $url = isset($_GET['url']) ? $_GET['url'] : '';
    
    if (empty($url)) {
        echo json_encode([
            'valid' => false,
            'error' => 'Missing url parameter'
        ]);
        exit;
    }
    
    // First get video URL from prehraj.to page
    if (strpos($url, 'prehraj.to') !== false) {
        // Fetch the page and extract video URL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || empty($html)) {
            echo json_encode([
                'valid' => false,
                'status' => $httpCode,
                'error' => 'Cannot fetch prehraj.to page'
            ]);
            exit;
        }
        
        // Extract video URL
        $videoUrl = null;
        $patterns = [
            '/["\']?(https?:\/\/[^"\']*premiumcdn\.net[^"\']*\.m3u8[^"\']*)["\']?/i',
            '/["\']?(https?:\/\/[^"\']*premiumcdn\.net[^"\']*\.mp4[^"\']*)["\']?/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $videoUrl = str_replace('\/', '/', $matches[1]);
                break;
            }
        }
        
        if (!$videoUrl) {
            echo json_encode([
                'valid' => false,
                'error' => 'Video URL not found in page'
            ]);
            exit;
        }
        
        $url = $videoUrl;
    }
    
    // Do partial GET request instead of HEAD (HLS streams don't respond to HEAD)
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_NOBODY, false);  // GET instead of HEAD
    curl_setopt($ch, CURLOPT_RANGE, '0-1024'); // Only first 1KB
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_REFERER, 'https://prehraj.to/');  // Required by some CDNs
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);  // Increased from 10

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 206 = Partial Content (success for Range request), 200 = OK
    $valid = ($httpCode >= 200 && $httpCode < 400);
    
    echo json_encode([
        'valid' => $valid,
        'status' => $httpCode
    ]);
    exit;
}

/**
 * Handle video URL extraction
 */
function handleVideo() {
    // Get URL parameter
    $url = isset($_GET['url']) ? $_GET['url'] : '';
    
    if (empty($url)) {
        echo json_encode([
            'success' => false,
            'error' => 'Missing url parameter',
            'usage' => 'index.php?url=https://prehraj.to/video/...'
        ]);
        exit;
    }
    
    // Validate URL is from prehraj.to
    if (strpos($url, 'prehraj.to') === false) {
        echo json_encode([
            'success' => false,
            'error' => 'URL must be from prehraj.to'
        ]);
        exit;
    }
    
    // Fetch the page
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo json_encode([
            'success' => false,
            'error' => 'Curl error: ' . $error
        ]);
        exit;
    }
    
    if ($httpCode !== 200) {
        echo json_encode([
            'success' => false,
            'error' => 'HTTP error: ' . $httpCode
        ]);
        exit;
    }
    
    // Try to find video URL in various formats
    $videoUrl = null;
    $patterns = [
        // HLS playlist
        '/["\']?(https?:\/\/[^"\']*premiumcdn\.net[^"\']*\.m3u8[^"\']*)["\']?/i',
        // MP4 direct
        '/["\']?(https?:\/\/[^"\']*premiumcdn\.net[^"\']*\.mp4[^"\']*)["\']?/i',
        // Generic CDN URL
        '/["\']?(https?:\/\/[^"\']*cdn[^"\']*\.(m3u8|mp4)[^"\']*)["\']?/i',
        // Source tag
        '/<source[^>]+src=["\']([^"\']+)["\'][^>]*>/i',
        // Video tag
        '/<video[^>]+src=["\']([^"\']+)["\'][^>]*>/i',
        // JSON data
        '/"file"\s*:\s*"([^"]+)"/i',
        '/"src"\s*:\s*"([^"]+)"/i',
        '/"url"\s*:\s*"([^"]+\.m3u8[^"]*)"/i',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $matches)) {
            $candidate = $matches[1];
            // Check if it looks like a video URL
            if (preg_match('/\.(m3u8|mp4)/i', $candidate)) {
                $videoUrl = $candidate;
                break;
            }
        }
    }
    
    // Also try to find all potential video URLs
    $allUrls = [];
    if (preg_match_all('/https?:\/\/[^"\'<>\s]+\.(m3u8|mp4)[^"\'<>\s]*/i', $html, $allMatches)) {
        $allUrls = array_unique($allMatches[0]);
    }
    
    if ($videoUrl) {
        // Unescape if needed
        $videoUrl = str_replace('\/', '/', $videoUrl);
        
        echo json_encode([
            'success' => true,
            'videoUrl' => $videoUrl,
            'allUrls' => array_values($allUrls),
            'source' => 'prehraj.to proxy'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Video URL not found in page',
            'allUrls' => array_values($allUrls),
            'hint' => 'Page might require JavaScript or different parsing'
        ]);
    }
}
