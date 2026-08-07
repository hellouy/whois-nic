<?php
// 说明：本应用不使用 $_SESSION（语言偏好走独立的 lang cookie）。
// 此前的 session_start() 会在每个响应写入 Set-Cookie: PHPSESSID，
// 而携带 Set-Cookie 的响应无法被 Vercel Edge CDN 缓存。移除后，
// 无状态接口（favicon 代理、JSON API、manifest 等）可被 CDN 命中，
// 大幅减少函数调用与冷启动，提升全球访问速度。

// 解析域名参数
$domain = null;

if (isset($_GET['domain']) && !empty(trim($_GET['domain']))) {
    $domain = trim($_GET['domain']);
}

if (!$domain && isset($_SERVER['REQUEST_URI'])) {
    if (preg_match('#^/([^/]+?)(?:\?|/|$)#', $_SERVER['REQUEST_URI'], $matches)) {
        $potentialDomain = $matches[1];
        if (preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$/', $potentialDomain)) {
            $domain = $potentialDomain;
            $_GET['domain'] = $domain;
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  http_response_code(405);
  header("Allow: GET");
  die("Method not allowed");
}

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../vendor/autoload.php";

// 自动加载器与请求辅助函数（checkPassword / cleanDomain / getDataSource）
require_once __DIR__ . "/lib/helpers.php";

// WHOIS/RDAP 结果本地缓存（避免重复查询、优化冷启动后的热请求）
require_once __DIR__ . "/lib/lookup-cache.php";

// 多域名批量查询（暗号 "0" 触发）
require_once __DIR__ . "/lib/multi-lookup.php";

// 多语言（i18n）：在任何输出前初始化，以便正确写入 lang cookie
require_once __DIR__ . "/lib/i18n.php";
i18n_init();

use Pdp\SyntaxError;
use Pdp\UnableToResolveDomain;

// ---- 网站图标代理接口（?api=favicon&domain=example.com）----
// 为什么需要服务端代理：Google / DuckDuckGo 的 favicon 服务在国内浏览器常被墙，
// 直连站点 /favicon.ico 又受 HTTPS 证书 / 跨域 / 无图标等问题困扰，导致图标加载失败。
// 本接口在 Vercel（美国）服务端抓取图标再以"同源图片"返回，国内一定可达、无 CORS，
// 浏览器还能长期缓存。前端只需 <img src="?api=favicon&domain=x">。
if (isset($_GET["api"]) && $_GET["api"] === "favicon") {
  $d = isset($_GET["domain"]) ? strtolower(trim($_GET["domain"])) : "";

  // 严格校验域名，避免 SSRF（只允许合法域名字符）
  if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $d)) {
    http_response_code(400);
    exit;
  }

  // 多源逐个尝试：Google（清晰、覆盖广，sz=128 更锐利）→ DuckDuckGo → 站点自身 /favicon.ico
  $sources = [
    "https://www.google.com/s2/favicons?sz=128&domain=" . urlencode($d),
    "https://icons.duckduckgo.com/ip3/" . urlencode($d) . ".ico",
    "https://" . $d . "/favicon.ico",
  ];

  // 校验二进制是否为真图片：按魔数识别 PNG/ICO/GIF/JPEG/WebP/SVG，
  // 避免把 HTML 错误页当作图标缓存下来。
  $looksLikeImage = function ($bin) {
    if (strlen($bin) < 24) {
      return false;
    }
    $h = substr($bin, 0, 12);
    if (substr($h, 0, 8) === "\x89PNG\r\n\x1a\n") return "image/png";           // PNG
    if (substr($h, 0, 4) === "\x00\x00\x01\x00") return "image/x-icon";          // ICO
    if (substr($h, 0, 3) === "GIF") return "image/gif";                          // GIF
    if (substr($h, 0, 2) === "\xff\xd8") return "image/jpeg";                    // JPEG
    if (substr($h, 0, 4) === "RIFF" && substr($bin, 8, 4) === "WEBP") return "image/webp"; // WebP
    if (stripos($bin, "<svg") !== false) return "image/svg+xml";                 // SVG
    return false;
  };

  $img = null;
  $ctype = "image/png";
  foreach ($sources as $src) {
    $ch = curl_init($src);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 3,
      CURLOPT_CONNECTTIMEOUT => 3,
      CURLOPT_TIMEOUT => 5,
      CURLOPT_SSL_VERIFYPEER => false, // 站点证书不规范时也尽量取到图标
      CURLOPT_USERAGENT => "Mozilla/5.0 (compatible; WhoisFaviconBot/1.0)",
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 有效图标：HTTP 2xx、内容够大、且魔数确认是真图片
    if ($body !== false && $code >= 200 && $code < 300 && strlen($body) > 70) {
      $detected = $looksLikeImage($body);
      if ($detected) {
        $img = $body;
        $ctype = $detected;
        break;
      }
    }
  }

  if ($img === null) {
    // 全部失败：返回 204，前端据此保留通用链接图标
    // s-maxage 让 Vercel Edge CDN 也缓存该结果，避免重复回源抓取
    header("Cache-Control: public, max-age=86400, s-maxage=86400, stale-while-revalidate=604800");
    http_response_code(204);
    exit;
  }

  header("Content-Type: " . $ctype);
  // 缓存 7 天（浏览器 + Edge CDN），并允许过期后先返回旧图再后台刷新
  header("Cache-Control: public, max-age=604800, s-maxage=604800, stale-while-revalidate=2592000");
  header("Content-Length: " . strlen($img));
  echo $img;
  exit;
}

checkPassword();

// ---- 暗号：多域名批量查询（输入 "0" 触发）----
// 交互：输入 0 → 点击查询 → 进入多域名模式（搜索框提示输入后缀）→
//       输入后缀 → 查询 34 个固定前缀域名（a-z + nic/www/com/net/org/dns/api/pay）。
$multiMode = false;   // 是否处于多域名模式
$multiEntry = false;  // 仅输入 0、尚未输入后缀（模式入口）
$multiSuffix = "";    // 用户输入的后缀
$multiData = null;    // 批量查询结果
// 注意：不能用 $domain，因为 PHP 的 empty("0") 为 true，顶部过滤会让输入 "0"
// 时 $domain 变成 null。这里直接读取原始 GET 参数以正确捕获暗号 "0"。
$rawDomain = isset($_GET["domain"]) ? trim((string) $_GET["domain"]) : "";
$multiFlag = filter_var($_GET["multi"] ?? 0, FILTER_VALIDATE_BOOL);

// 嵌入模式：多域名列表点击某项时，在弹窗 iframe 内加载该域名的完整查询结果，
// 隐藏顶栏 / 搜索框 / 页脚等外层 chrome，仅展示结果卡片。
$embed = filter_var($_GET["embed"] ?? 0, FILTER_VALIDATE_BOOL);

if ($rawDomain === "0") {
  // 暗号触发：进入多域名模式入口，等待用户输入后缀
  $multiMode = true;
  $multiEntry = true;
  $domain = "";
  } elseif ($multiFlag && $rawDomain !== "") {
    // 多域名模式下已输入后缀：执行批量查询。
    // 用 try/catch 兜底：探测涉及网络/DNS，任何异常都不应让整页 500 崩溃，
    // 而是退化为“空结果”让前端优雅提示（与单域名查询同等健壮）。
    $multiMode = true;
    $multiSuffix = sanitizeSuffix($rawDomain);
    try {
      $multiData = multiDomainLookup($multiSuffix);
    } catch (Throwable $t) {
      $multiData = [
        "suffix" => $multiSuffix,
        "extension" => $multiSuffix,
        "source" => "error",
        "items" => [],
        "elapsed" => 0.0,
      ];
    }
    $domain = "";
  }

// 多域名模式下强制清空域名，避免 cleanDomain 回退读取 $_GET["domain"]（如 "0"/"com"）
// 从而误触发单域名查询；非多域名模式保持原有行为。
$domain = $multiMode ? "" : cleanDomain($domain);

$dataSource = [];
$fetchPrices = false;
$fetchBeiAn = false;
$whoisData = null;
$rdapData = null;
$parser = new Parser("");
$error = null;
$invalidDomain = false; // 仅当域名格���/后缀真正非法时为 true
$dnsActive = false; // DNS 层面是否检测到域名已被注册/在用
$dnsInfo = null; // WHOIS/RDAP 缺失时，DNS 兜底拉取到的实际记录（NS/A/AAAA/MX）

if ($domain) {
  $dataSource = getDataSource();
  $fetchPrices = filter_var($_GET["prices"] ?? 0, FILTER_VALIDATE_BOOL);
  $fetchBeiAn = filter_var($_GET["beian"] ?? 0, FILTER_VALIDATE_BOOL);

  // 是否绕过缓存（?nocache=1 强制刷新，便于排查与获取最新数据）
  $noCache = filter_var($_GET["nocache"] ?? 0, FILTER_VALIDATE_BOOL);
  $cacheHit = false;

  try {
    $queryStart = microtime(true);

    // ---- 优先命中本地缓存 ----
    $cached = $noCache ? null : lookup_cache_get($domain, $dataSource);
    if ($cached !== null) {
      $cacheHit = true;
      $domain = $cached["domain"] ?: $domain;
      $whoisData = $cached["whoisData"];
      $rdapData = $cached["rdapData"];
      $parser = $cached["parser"];
      $dnsActive = $cached["dnsActive"];
      $dnsInfo = $cached["dnsInfo"] ?? null;
      if (($cached["extension"] ?? "") === "iana") {
        $fetchPrices = false;
      }
    } else {
      $lookup = new Lookup($domain, $dataSource);
      // 归一化后回填；若解析器未返回域名，则保留用户查询值，确保搜索框始终回显
      $domain = $lookup->domain ?: $domain;
      $whoisData = $lookup->whoisData;
      $rdapData = $lookup->rdapData;
      $parser = $lookup->parser;

      if ($lookup->extension === "iana") {
        $fetchPrices = false;
      }

      // 当 WHOIS/RDAP 判定为"未注册/未知"（既非已注册，也非保留/禁止）时，
      // 追加 DNS 校验以提升准确性：若存在 NS/A/AAAA/MX 记录，说明域名其实已被注册。
      if (
        $lookup->extension !== "iana" &&
        !$parser->registered &&
        !$parser->reserved &&
        !$parser->prohibited
      ) {
        $dnsActive = domainHasDnsRecords($domain);
        // 已确认在用但无 WHOIS/RDAP 详情：补拉实际 DNS 记录用于展示
        if ($dnsActive) {
          $dnsInfo = domainDnsRecords($domain);
        }
      }

      // 写入缓存（仅缓存确定性结果；错误在 catch 分支不会写入）
      lookup_cache_set($domain, $dataSource, [
        "domain" => $domain,
        "whoisData" => $whoisData,
        "rdapData" => $rdapData,
        "parser" => $parser,
        "dnsActive" => $dnsActive,
        "dnsInfo" => $dnsInfo,
        "extension" => $lookup->extension,
      ]);
    }

    // 计时覆盖 WHOIS/RDAP + DNS 兜底的完整服务端查询耗时，
    // 使显示的耗时与真实等待一致（此前仅计 Lookup，未含 DNS 兜底）。
    $queryElapsed = microtime(true) - $queryStart;

    // 便于排查：标记本次结果是否来自缓存
    if (!headers_sent()) {
      header("X-Whois-Cache: " . ($cacheHit ? "HIT" : "MISS"));
    }
  } catch (Exception $e) {
    if ($e instanceof SyntaxError || $e instanceof UnableToResolveDomain) {
      // 真正的格式/后缀非法：才算"无效域名"
      $invalidDomain = true;
      $error = "'$domain' is not a valid domain";
    } else {
      // WHOIS/RDAP 查询失败（服务器不可达、超时、注册局接口异常等）：
      // 这不代表域名无效。退一步用 DNS 兜底判断是否已注册，尽量给出有用结果。
      $error = $e->getMessage();
      $dnsActive = domainHasDnsRecords($domain);
      if ($dnsActive) {
        $dnsInfo = domainDnsRecords($domain);
      }
    }
  }

  if (filter_var($_GET["json"] ?? 0, FILTER_VALIDATE_BOOL)) {
    header("Access-Control-Allow-Origin: *");
    header("Content-Type: application/json");

    if ($error) {
      // 错误结果不缓存（可能是临时性网络/注册局故障，下次应重试）
      header("Cache-Control: no-store");
      $value = ["code" => 1, "msg" => $error, "data" => null];
    } else {
      // JSON 数据与语言无关，非常适合 Edge CDN 缓存。
      // 按状态分级设置 s-maxage：已注册结果稳定→缓存更久；未注册可能随时被抢注→更短。
      $sMaxAge = 3600; // 默认 1 小时
      if ($parser->registered) {
        $sMaxAge = 21600; // 已注册：6 小时
      } elseif ($parser->reserved || $parser->prohibited) {
        $sMaxAge = 86400; // 保留/禁止：24 小时
      } else {
        $sMaxAge = 1800; // 未注册/未知：30 分钟
      }
      header("Cache-Control: public, max-age=0, s-maxage={$sMaxAge}, stale-while-revalidate=86400");
      // 清洗注册商名称（去除隐私占位符），使 JSON 接口与批量弹窗展示保持一致
      if ($parser->registered && $parser->registrar) {
        require_once __DIR__ . "/lib/registrar-map.php";
        $parser->registrar = registrar_clean($parser->registrar);
      }
      $value = ["code" => 0, "msg" => "Query successful", "data" => $parser];
      // WHOIS/RDAP 无详情但 DNS 兜底判定已注册时，附带实际 DNS 记录
      if (!$parser->registered && $dnsActive && $dnsInfo) {
        $value["dnsActive"] = true;
        $value["dns"] = $dnsInfo;
      }
    }

    $json = json_encode($value, JSON_UNESCAPED_UNICODE);

    if ($json === false) {
      $value = ["code" => 1, "msg" => json_last_error_msg(), "data" => null];
      echo json_encode($value, JSON_UNESCAPED_UNICODE);
    } else {
      echo $json;
    }

    die;
  }
}

// 分享/Manifest 元数据（$manifestHref, $currentUrl, $shareImage, $shareTitle, $shareDescription）
require __DIR__ . "/lib/share-meta.php";
?>
  <!DOCTYPE html>
  <html lang="<?= htmlspecialchars(i18n_html_lang(), ENT_QUOTES, 'UTF-8'); ?>">

  <?php require __DIR__ . "/partials/head.php"; ?>

<body data-has-domain="<?= $domain ? "1" : "0"; ?>"<?= $embed ? ' class="is-embed"' : ''; ?>>
  <?php if (!$embed): ?>
    <?php require __DIR__ . "/partials/topbar.php"; ?>
    <?php require __DIR__ . "/partials/search-form.php"; ?>
  <?php endif; ?>
  <main>
    <?php if ($multiMode): ?>
      <?php require __DIR__ . "/partials/multi-result.php"; ?>
    <?php else: ?>
      <?php require __DIR__ . "/partials/result.php"; ?>
      <?php require __DIR__ . "/partials/raw-data.php"; ?>
    <?php endif; ?>
  </main>
  <?php if (!$embed): ?>
    <?php require_once __DIR__ . "/footer.php"; ?>
    <button class="back-to-top" id="back-to-top">
      <svg width="1em" height="1em" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
        <path d="M8 12a.5.5 0 0 0 .5-.5V5.707l2.146 2.147a.5.5 0 0 0 .708-.708l-3-3a.5.5 0 0 0-.708 0l-3 3a.5.5 0 1 0 .708.708L7.5 5.707V11.5a.5.5 0 0 0 .5.5" fill-rule="evenodd" />
      </svg>
    </button>
  <?php endif; ?>
  <?php require __DIR__ . "/partials/scripts.php"; ?>
  <?= CUSTOM_SCRIPT ?>
</body>
<script defer src="https://umami-rho-blue.vercel.app/script.js" data-website-id="ad534fcf-b898-4f4c-b80b-c910820e206f"></script>
</html>
