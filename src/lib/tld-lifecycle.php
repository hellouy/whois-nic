<?php

/**
 * 域名释放（可重新注册）时间预测
 * ------------------------------------------------------------------
 * 依据 src/data/tld-lifecycle.php 中的后缀生命周期数据，结合域名到期日、
 * EPP 状态码与 WHOIS/RDAP 提供的"注册局报告可注册日"，推算域名进入公开
 * 可注册状态的大致时间。
 *
 * 输出字段说明：
 *   predictable         true=有固定生命周期阶段；false=无标准删除周期
 *   tld / registry      后缀与注册局名称
 *   confidence          "high"=ICANN/注册局明确政策；"est"=公开资料估算
 *   source              "registry"=注册局直接报告；
 *                       "registry+estimate"=注册局报告与估算交叉校准；
 *                       "anchor"=以"最后变更"日期为锚点；
 *                       "lifecycle"=纯生命周期推算
 *   releaseTs / releaseDate   预计释放时间戳与日期
 *   window              [earliest, latest, marginDays] 释放时间窗口
 *                       confidence=high 时 margin=±1 天，est 时 margin=±5 天
 *   phases              各阶段边界（renewGrace/redemption/pendingDelete）
 *   currentPhase        active / renewGrace / redemption / pendingDelete /
 *                       released / hold / inactive / unknown
 *   registryAvailableTs 注册局直接报告的可用日期（availableDate 字段），可空
 */

/**
 * 状态码 → 阶段识别（按优先级匹配）。
 * EPP/RDAP status 中存在大量别名（clientTransferProhibited、pendingrestore
 * 等），这里按"越接近释放越优先"排序，便于更准确判断当前阶段。
 */
function release_forecast_detect_phase(array $statusCodes): ?string
{
    $lc = array_map(fn($s) => strtolower(trim((string) $s)), $statusCodes);
    $has = function (array $needles) use ($lc): bool {
        foreach ($lc as $s) {
            if ($s === '') continue;
            foreach ($needles as $n) {
                $n = strtolower($n);
                // 精确匹配或单词边界匹配（避免 "active" 误中 "inactive"）
                if ($s === $n) return true;
                if (preg_match('/(^|[\s\-])' . preg_quote($n, '/') . '($|[\s\-])/', $s)) return true;
            }
        }
        return false;
    };

    // 待删除阶段（最接近释放）
    if ($has(['pendingdelete', 'pending-delete', 'redemptiongraceperiod'])) {
        return 'pendingDelete';
    }
    // 赎回期（RGP）
    if ($has(['redemptionperiod', 'redemption', 'redemption-pendingrestore', 'pendingrestore', 'redemptionrestorable'])) {
        return 'redemption';
    }
    // 续费宽限期
    if ($has(['renewperiod', 'auto-renew', 'autorenew', 'graceperiod', 'addperiod'])) {
        return 'renewGrace';
    }
    // 暂停状态（clienthold / serverhold / pendingstatuschange）
    // 常见于过期后被注册商挂起但还未进入 RGP，或注册局层面的强制暂停
    if ($has(['serverhold', 'server-hold', 'clienthold', 'client-hold', 'pendingstatuschange'])) {
        return 'hold';
    }
    // 冷门状态
    if ($has(['inactive', 'obfuscated'])) {
        return 'inactive';
    }
    return null;
}

/** 取某后缀的生命周期配置（缺省字段回退到 _default 的 gTLD 标准）。 */
function tld_lifecycle_config(string $tld): array
{
    static $db = null;
    if ($db === null) {
        $db = require __DIR__ . "/../data/tld-lifecycle.php";
    }
    $tld = strtolower(ltrim(trim($tld), "."));
    $default = $db["_default"];
    $conf = $db[$tld] ?? $default;
    if (!isset($conf["predictable"])) {
        $conf["predictable"] = !empty($conf["renewGrace"]) || !empty($conf["redemption"]) || !empty($conf["pendingDelete"]);
    }
    // 用 _default 补齐缺省的天数字段，避免未定义键
    return $conf + $default;
}

/** 从完整域名取最后一级后缀（生命周期由实际注册局决定，末级标签即可）。 */
function tld_from_domain(string $domain): string
{
    $domain = strtolower(rtrim(trim($domain), "."));
    $pos = strrpos($domain, ".");
    return $pos === false ? $domain : substr($domain, $pos + 1);
}

/**
 * 生成释放时间预测。
 *
 * @param string      $domain              完整域名（用于取后缀）
 * @param string|null $expirationISO       到期日（ISO8601 / 可被 strtotime 解析）
 * @param array       $statusCodes         EPP 状态码数组（如 ["redemptionPeriod", ...]），用于校准当前阶段
 * @param string|null $anchorISO           "最后变更"日期（WHOIS updated / RDAP last changed）。
 *                                          当域名已处于赎回/待删除阶段时，该日期通常正是进入当前
 *                                          阶段的时间点，用它锚定可比"到期日+固定偏移"更精确地
 *                                          推算真实删除/释放时间（规避注册商延迟进入删除流程的差异）。
 * @param string|null $availableAnchorISO  注册局/注册商直接报告的"可注册日期"。
 *                                          部分注册局（如 Denic / Nominet / Afilias）会在
 *                                          WHOIS availableDate 或 RDAP events[].eventDate
 *                                          直接返回预计删除/释放时间，作为权威锚点。
 *
 * @return array|null  无到期日与注册局报告时返回 null
 */
function domain_release_forecast(
    string $domain,
    ?string $expirationISO,
    array $statusCodes = [],
    ?string $anchorISO = null,
    ?string $availableAnchorISO = null
): ?array {
    $exp = $expirationISO ? strtotime($expirationISO) : false;
    $regAnchor = $availableAnchorISO ? strtotime($availableAnchorISO) : false;
    if ($exp === false) $exp = null;

    if ($exp === null && $regAnchor === false) {
        return null;
    }

    $tld = tld_from_domain($domain);
    $conf = tld_lifecycle_config($tld);
    $registry = $conf["registry"] ?? null;
    $confidence = $conf["confidence"] ?? "est";
    $predictable = !($conf["predictable"] === false);

    $currentPhase = release_forecast_detect_phase($statusCodes);

    $day = 86400;
    $now = time();

    // 注册局报告日期
    $registryAvailableTs = ($regAnchor !== false && $regAnchor > 0) ? $regAnchor : null;

    // ------ 无固定删除周期的后缀 ------
    if (!$predictable) {
        if ($registryAvailableTs !== null) {
            return [
                "predictable"           => false,
                "tld"                   => $tld,
                "registry"              => $registry,
                "confidence"            => $confidence,
                "source"                => "registry",
                "currentPhase"          => $currentPhase,
                "registryAvailableTs"   => $registryAvailableTs,
                "registryAvailableDate" => date("Y-m-d", $registryAvailableTs),
                "releaseTs"             => $registryAvailableTs,
                "releaseDate"           => date("Y-m-d", $registryAvailableTs),
                "daysUntilRelease"      => (int) ceil(($registryAvailableTs - $now) / $day),
                "released"              => $now >= $registryAvailableTs,
                "window"                => null,
                "phases"                => [],
            ];
        }
        return [
            "predictable"  => false,
            "tld"          => $tld,
            "registry"     => $registry,
            "confidence"   => $confidence,
            "currentPhase" => $currentPhase,
        ];
    }

    // ------ 正常生命周期预测 ------
    $renewDays  = (int) ($conf["renewGrace"] ?? 0);
    $redeemDays = (int) ($conf["redemption"] ?? 0);
    $pendingDays = (int) ($conf["pendingDelete"] ?? 0);
    $totalDays  = $renewDays + $redeemDays + $pendingDays;

    // 阶段边界
    $renewEnd = $exp !== null ? $exp + $renewDays * $day : null;
    $redeemEnd = $renewEnd !== null ? $renewEnd + $redeemDays * $day : null;
    $pendingEnd = $redeemEnd !== null ? $redeemEnd + $pendingDays * $day : null;
    $releaseTs = $pendingEnd;

    // 构建阶段列表
    $phases = [];
    if ($exp !== null) {
        $phases[] = ["key" => "renewGrace",    "start" => $exp,      "end" => $renewEnd,  "days" => $renewDays];
        $phases[] = ["key" => "redemption",    "start" => $renewEnd, "end" => $redeemEnd, "days" => $redeemDays];
        $phases[] = ["key" => "pendingDelete", "start" => $redeemEnd,"end" => $pendingEnd,"days" => $pendingDays];
    }

    // 阶段识别（无 EPP 状态时按时间推算）
    if ($currentPhase === null) {
        if ($exp === null) {
            $currentPhase = "unknown";
        } elseif ($now < $exp) {
            $currentPhase = "active";
        } elseif ($renewEnd !== null && $now < $renewEnd && $renewDays > 0) {
            $currentPhase = "renewGrace";
        } elseif ($redeemEnd !== null && $now < $redeemEnd && $redeemDays > 0) {
            $currentPhase = "redemption";
        } elseif ($pendingEnd !== null && $now < $pendingEnd && $pendingDays > 0) {
            $currentPhase = "pendingDelete";
        } elseif ($releaseTs !== null && $now >= $releaseTs) {
            $currentPhase = "released";
        } else {
            $currentPhase = "unknown";
        }
    }

    // 锚点校准：以"最后变更"日期为当前阶段起点
    $anchor = $anchorISO ? strtotime($anchorISO) : false;
    $anchored = false;
    if ($anchor !== false && $anchor > 0) {
        if ($currentPhase === "pendingDelete" && $pendingDays > 0 && $exp !== null) {
            $pendingEnd = $anchor + $pendingDays * $day;
            $redeemEnd  = $anchor;
            $renewEnd   = $exp + $renewDays * $day; // 续费宽限起点保持为到期日
            $releaseTs  = $pendingEnd;
            $anchored = true;
        } elseif ($currentPhase === "redemption" && $redeemDays > 0 && $exp !== null) {
            $redeemEnd  = $anchor + $redeemDays * $day;
            $pendingEnd = $redeemEnd + $pendingDays * $day;
            $renewEnd   = $exp + $renewDays * $day;
            $releaseTs  = $pendingEnd;
            $anchored = true;
        }
    }

    // 锚点校准后同步刷新受影响的阶段边界
    if ($anchored) {
        foreach ($phases as &$ph) {
            if ($ph["key"] === "redemption") {
                $ph["start"] = $renewEnd;
                $ph["end"] = $redeemEnd;
            } elseif ($ph["key"] === "pendingDelete") {
                $ph["start"] = $redeemEnd;
                $ph["end"] = $pendingEnd;
            }
        }
        unset($ph);
    }

    // 来源判定与注册局报告交叉验证
    $source = $anchored ? "anchor" : "lifecycle";
    if ($registryAvailableTs !== null) {
        // 注册局报告与估算：差异 5 天以上时认为注册局报告更权威
        $diffDays = $releaseTs !== null ? abs($releaseTs - $registryAvailableTs) / $day : 0;
        if ($diffDays > 5) {
            // 取更晚者作为保守释放日（避免对"提早释放"过于乐观）
            $releaseTs = max($releaseTs ?? 0, $registryAvailableTs);
            $source = "registry+estimate";
            // 重算受影响的窗口
        } else {
            $source = $anchored ? "anchor+registry" : "registry+estimate";
        }
    }

    $windowMargin = ($confidence === "high") ? 1 : 5;
    $window = null;
    if ($releaseTs !== null) {
        $window = [
            "earliest"   => date("Y-m-d", $releaseTs - $windowMargin * $day),
            "latest"     => date("Y-m-d", $releaseTs + $windowMargin * $day),
            "marginDays" => $windowMargin,
        ];
    }

    $daysUntilRelease = $releaseTs !== null ? (int) ceil(($releaseTs - $now) / $day) : null;
    $released = $releaseTs !== null && $now >= $releaseTs;

    return [
        "predictable"           => true,
        "tld"                   => $tld,
        "registry"              => $registry,
        "confidence"            => $confidence,
        "source"                => $source,
        "expiration"            => $exp,
        "currentPhase"          => $currentPhase,
        "phases"                => $phases,
        "renewEnd"              => $renewEnd,
        "redeemEnd"             => $redeemEnd,
        "pendingEnd"            => $pendingEnd,
        "releaseTs"             => $releaseTs,
        "releaseDate"           => $releaseTs !== null ? date("Y-m-d", $releaseTs) : null,
        "daysUntilRelease"      => $daysUntilRelease,
        "released"              => $released,
        "window"                => $window,
        "registryAvailableTs"   => $registryAvailableTs,
        "registryAvailableDate" => $registryAvailableTs !== null ? date("Y-m-d", $registryAvailableTs) : null,
        "anchored"              => $anchored,
        "totalDays"             => $totalDays,
    ];
}
