<?php

/**
 * Centralized SEO config and tag renderer.
 */
function seoLoadSettings(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    $settings = [
        'site_title' => '',
        'meta_description' => '',
        'meta_keywords' => '',
        'og_image_url' => '',
        'robots_policy' => 'index,follow',
        'google_analytics_id' => '',
        'google_tag_manager_id' => '',
    ];

    try {
        require_once __DIR__ . '/../api/db.php';
        if (!function_exists('getDB')) {
            return $settings;
        }

        $db = getDB();
        $keys = [
            'seo.site_title',
            'seo.meta_description',
            'seo.meta_keywords',
            'seo.og_image_url',
            'seo.robots_policy',
            'seo.google_analytics_id',
            'seo.google_tag_manager_id',
        ];

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $db->prepare('SELECT config_key, config_value FROM app_settings WHERE config_key IN (' . $placeholders . ')');
        $stmt->execute($keys);

        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['config_key'] ?? '');
            $value = trim((string) ($row['config_value'] ?? ''));
            if ($key === 'seo.site_title') {
                $settings['site_title'] = $value;
            } elseif ($key === 'seo.meta_description') {
                $settings['meta_description'] = $value;
            } elseif ($key === 'seo.meta_keywords') {
                $settings['meta_keywords'] = $value;
            } elseif ($key === 'seo.og_image_url') {
                $settings['og_image_url'] = $value;
            } elseif ($key === 'seo.robots_policy') {
                $settings['robots_policy'] = $value !== '' ? $value : 'index,follow';
            } elseif ($key === 'seo.google_analytics_id') {
                $settings['google_analytics_id'] = strtoupper($value);
            } elseif ($key === 'seo.google_tag_manager_id') {
                $settings['google_tag_manager_id'] = strtoupper($value);
            }
        }
    } catch (Throwable $e) {
        // Keep rendering resilient when DB is unavailable.
    }

    if (!preg_match('/^G-[A-Z0-9]+$/', $settings['google_analytics_id'])) {
        $settings['google_analytics_id'] = '';
    }

    if (!preg_match('/^GTM-[A-Z0-9]+$/', $settings['google_tag_manager_id'])) {
        $settings['google_tag_manager_id'] = '';
    }

    return $settings;
}

function seoEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function seoComposeTitle(string $pageTitle): string
{
    $settings = seoLoadSettings();
    $siteTitle = trim((string) ($settings['site_title'] ?? ''));
    $pageTitle = trim($pageTitle);

    if ($siteTitle === '') {
        return $pageTitle;
    }

    if ($pageTitle === '') {
        return $siteTitle;
    }

    if (stripos($pageTitle, $siteTitle) !== false) {
        return $pageTitle;
    }

    return $pageTitle . ' | ' . $siteTitle;
}

function seoRenderHeadTags(string $pageTitle = ''): void
{
    $settings = seoLoadSettings();
    $webSettings = webLoadSettings();
    $title = seoComposeTitle($pageTitle);

    echo '<title>' . seoEscape($title) . '</title>' . PHP_EOL;

    $description = trim((string) ($settings['meta_description'] ?? ''));
    if ($description !== '') {
        echo '<meta name="description" content="' . seoEscape($description) . '">' . PHP_EOL;
    }

    $keywords = trim((string) ($settings['meta_keywords'] ?? ''));
    if ($keywords !== '') {
        echo '<meta name="keywords" content="' . seoEscape($keywords) . '">' . PHP_EOL;
    }

    $robots = trim((string) ($settings['robots_policy'] ?? 'index,follow'));
    if ($robots === '') {
        $robots = 'index,follow';
    }
    echo '<meta name="robots" content="' . seoEscape($robots) . '">' . PHP_EOL;

    echo '<meta property="og:title" content="' . seoEscape($title) . '">' . PHP_EOL;
    if ($description !== '') {
        echo '<meta property="og:description" content="' . seoEscape($description) . '">' . PHP_EOL;
    }

    if (!empty($settings['site_title'])) {
        echo '<meta property="og:site_name" content="' . seoEscape((string) $settings['site_title']) . '">' . PHP_EOL;
    }

    $favicon = webPublicAssetUrl((string) ($webSettings['favicon_url'] ?? ''));
    if ($favicon !== '') {
        echo '<link rel="icon" href="' . seoEscape($favicon) . '">' . PHP_EOL;
        echo '<link rel="shortcut icon" href="' . seoEscape($favicon) . '">' . PHP_EOL;
    }

    $logo = webPublicAssetUrl((string) ($webSettings['logo_url'] ?? ''));
    if ($logo !== '') {
        echo '<meta name="web-logo-url" content="' . seoEscape($logo) . '">' . PHP_EOL;
    }

    $ogImage = trim((string) ($settings['og_image_url'] ?? ''));
    if ($ogImage !== '') {
        echo '<meta property="og:image" content="' . seoEscape($ogImage) . '">' . PHP_EOL;
    }

    $gaId = (string) ($settings['google_analytics_id'] ?? '');
    if ($gaId !== '') {
        echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . rawurlencode($gaId) . '"></script>' . PHP_EOL;
        echo '<script>' . PHP_EOL;
        echo 'window.dataLayer = window.dataLayer || [];' . PHP_EOL;
        echo 'function gtag(){dataLayer.push(arguments);}' . PHP_EOL;
        echo "gtag('js', new Date());" . PHP_EOL;
        echo "gtag('config', '" . seoEscape($gaId) . "');" . PHP_EOL;
        echo '</script>' . PHP_EOL;
    }

    $gtmId = (string) ($settings['google_tag_manager_id'] ?? '');
    if ($gtmId !== '') {
        echo '<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({\'gtm.start\':' . PHP_EOL;
        echo 'new Date().getTime(),event:\'gtm.js\'});var f=d.getElementsByTagName(s)[0],' . PHP_EOL;
        echo 'j=d.createElement(s),dl=l!=\'dataLayer\'?\'&l=\'+l:\'\';j.async=true;j.src=' . PHP_EOL;
        echo '\'https://www.googletagmanager.com/gtm.js?id=\'+i+dl;f.parentNode.insertBefore(j,f);' . PHP_EOL;
        echo '})(window,document,\'script\',\'dataLayer\',\'' . seoEscape($gtmId) . '\');</script>' . PHP_EOL;
    }
}

function seoRenderBodyOpenTags(): void
{
    $settings = seoLoadSettings();
    $gtmId = (string) ($settings['google_tag_manager_id'] ?? '');
    if ($gtmId === '') {
        return;
    }

    echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . seoEscape($gtmId) . '"' . PHP_EOL;
    echo 'height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . PHP_EOL;
}

function webLoadSettings(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    $settings = [
        'company_name' => 'DayNight',
        'template_name' => 'daynight-default',
        'font_family' => 'Poppins',
        'logo_url' => '',
        'favicon_url' => '',
    ];

    try {
        require_once __DIR__ . '/../api/db.php';
        if (!function_exists('getDB')) {
            return $settings;
        }

        $db = getDB();
        $keys = [
            'web.company_name',
            'web.template_name',
            'web.font_family',
            'web.logo_url',
            'web.favicon_url',
        ];

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $db->prepare('SELECT config_key, config_value FROM app_settings WHERE config_key IN (' . $placeholders . ')');
        $stmt->execute($keys);

        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['config_key'] ?? '');
            $value = trim((string) ($row['config_value'] ?? ''));
            if ($key === 'web.company_name') {
                $settings['company_name'] = $value !== '' ? $value : 'DayNight';
            } elseif ($key === 'web.template_name') {
                $settings['template_name'] = $value;
            } elseif ($key === 'web.font_family') {
                $settings['font_family'] = $value;
            } elseif ($key === 'web.logo_url') {
                $settings['logo_url'] = $value;
            } elseif ($key === 'web.favicon_url') {
                $settings['favicon_url'] = $value;
            }
        }
    } catch (Throwable $e) {
        // Keep rendering resilient when DB is unavailable.
    }

    return $settings;
}

function webCompanyName(string $fallback = 'TEAM KLICKnet'): string
{
    $settings = webLoadSettings();
    $companyName = trim((string) ($settings['company_name'] ?? ''));
    return $companyName !== '' ? $companyName : $fallback;
}

function webPublicAssetUrl(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $value)) {
        return $value;
    }

    if (str_starts_with($value, '/')) {
        return $value;
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    if ($scriptDir === '' || $scriptDir === '.') {
        return '/' . ltrim($value, '/');
    }

    return $scriptDir . '/' . ltrim($value, '/');
}

function webLogoUrl(): string
{
    $settings = webLoadSettings();
    return webPublicAssetUrl((string) ($settings['logo_url'] ?? ''));
}
