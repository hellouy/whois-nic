<?php
class Parser
{
  protected $dateFormat = null;

  protected $timezone = "UTC";

  protected $data = "";

  public $whoisData = "";

  public $rdapData = "";

  public $unknown = false;

  public $reserved = false;

  public $prohibited = false;

  public $registered = false;

  public $domain = "";

  public $registrar = "";

  public $registrarURL = "";

  public $creationDate = "";

  public $creationDateISO8601 = null;

  public $expirationDate = "";

  public $expirationDateISO8601 = null;

  public $updatedDate = "";

  public $updatedDateISO8601 = null;

  public $availableDate = "";

  public $availableDateISO8601 = null;

  public $status = [];

  public $nameServers = [];

  public $dnssec = "";

  public $age = "";

  public $ageSeconds = null;

  public $remaining = "";

  public $remainingSeconds = null;

  public $gracePeriod = false;

  public $redemptionPeriod = false;

  public $pendingDelete = false;

  public function __construct($data)
  {
    // 清除部分 registry 响应开头的 UTF-8 BOM 与零宽字符。
    // 若不清除，BOM 会附着到首个数据行，使该行字段（常为域名）匹配失败并返回空。
    // 保留原始 whoisData 用于“原始记录”展示，仅对参与解析的 data 做清洗。
    if (is_string($data) && $data !== "") {
      $data = preg_replace('/^\xEF\xBB\xBF/', '', $data); // UTF-8 BOM
      $data = preg_replace('/^[\x{FEFF}\x{200B}\x{200E}\x{200F}]+/u', '', $data); // 零宽/方向标记
    }

    $this->data = $data;
    $this->whoisData = $data;

    if (empty($this->data)) {
      $this->unknown = true;
      return;
    }

    $this->reserved = $this->getReserved();
    if ($this->reserved) {
      return;
    }

    $this->prohibited = $this->getProhibited();
    if ($this->prohibited) {
      return;
    }

    $this->registered = !$this->getUnregistered();
    if (!$this->registered) {
      return;
    }

    $this->domain = $this->getDomain();

    $this->registrar = $this->getRegistrar();
    $this->registrarURL = $this->getRegistrarURL();

    $this->creationDate = $this->getCreationDate();
    $this->creationDateISO8601 = $this->getCreationDateISO8601();

    $this->expirationDate = $this->getExpirationDate();
    $this->expirationDateISO8601 = $this->getExpirationDateISO8601();

    $this->updatedDate = $this->getUpdatedDate();
    $this->updatedDateISO8601 = $this->getUpdatedDateISO8601();

    $this->availableDate = $this->getAvailableDate();
    $this->availableDateISO8601 = $this->getAvailableDateISO8601();

    $this->status = $this->getStatus();
    $this->setStatusUrl();

    $this->nameServers = $this->getNameServers();

    $this->dnssec = $this->getDNSSEC();

    $this->age = $this->getDateDiffText($this->creationDateISO8601, "now");
    $this->ageSeconds = $this->getDateDiffSeconds($this->creationDateISO8601, "now");
    $this->remaining = $this->getDateDiffText("now", $this->expirationDateISO8601);
    $this->remainingSeconds = $this->getDateDiffSeconds("now", $this->expirationDateISO8601);

    $this->gracePeriod = $this->hasKeywordInStatus(self::GRACE_PERIOD_KEYWORDS);
    $this->redemptionPeriod = $this->hasKeywordInStatus(self::REDEMPTION_PERIOD_KEYWORDS);
    $this->pendingDelete = $this->hasKeywordInStatus(self::PENDING_DELETE_KEYWORDS);

    $this->removeEmptyValues();

    $this->unknown = $this->getUnknown();
    if ($this->unknown) {
      $this->registered = false;
    }
  }

  // 注册局“保留”关键词（仅匹配明确短语，避免误伤已注册域名）
  private const RESERVED_KEYWORDS = [
    "reserved by the registry", // ac
    "has been reserved", // ae
    "temporarily reserved", // am
    "reserved by registry", // au
    "reserved name", // generic
    "reserved domain name", // generic
    "this domain is reserved", // generic
    "domain is reserved", // generic
    "is a reserved name", // generic
    "registry reserved", // generic
  ];

  protected function getReservedRegExp()
  {
    return "/" . implode("|", self::RESERVED_KEYWORDS) . "/i";
  }

  protected function getReserved()
  {
    return (bool)preg_match($this->getReservedRegExp(), $this->data);
  }

  // 注册局“禁止/限制注册”关键词。
  // 重要：必须使用完整短语，绝不能用裸词 “prohibited / forbidden / restricted / blocked”，
  // 否则会把含 clientTransferProhibited / serverDeleteProhibited 等正常状态的已注册域名误判。
  private const PROHIBITED_KEYWORDS = [
    "registration status: forbidden", // bg
    "is on a restricted list", // bi
    "prohibited string", // bj
    "object is blocked", // by
    "registration of this domain is prohibited", // generic
    "registration is forbidden", // generic
    "registration is not allowed", // generic
    "registration restricted", // generic
    "this domain is restricted", // generic
    "domain name is blocked", // generic
    "blocked for registration", // generic
  ];

  protected function getProhibitedRegExp()
  {
    return "/" . implode("|", self::PROHIBITED_KEYWORDS) . "/i";
  }

  protected function getProhibited()
  {
    return (bool)preg_match($this->getProhibitedRegExp(), $this->data);
  }

  private const UNREGISTERED_KEYWORDS = [
    "no match", // com
    "not found", // ac
    "not exist", // ad
    "no data", // ae
    "nothing found", // at
    "status:\tavailable", // be
    "no object found", // bf
    "status: available", // bg
    "domain is available", // co.ca
    "no entries found", // cl
    "status: free", // de
    "is available for registration", // dm
    "has not been registered", // hk
    "no such domain", // lu
    "object_not_found", // mx
    "domain unknown", // pf
    "not registered", // pk
    "no information", // pl
    "no records found", // tj
    "is available for purchase", // tm
    "domain name is available", // tt
    "no found", // tw
    "no matching record", // generic
    "object does not exist", // generic
    "domain name not known", // generic
    "available for registration", // generic
    "domain status: free", // generic
    // ---- 扩充：更多注册局对"可注册/未注册"的明确表述（均为完整短语，避免误伤已注册域名）----
    "no matching objects", // generic
    "not been registered", // hk 等
    "domain not found", // generic
    "no such host", // generic
    "free for registration", // generic
    "domain available", // generic
    "domain name available", // generic
    "we do not have an entry", // generic
    "the queried object does not exist", // DENIC 风格
    "no data found", // generic
    "no matching entries", // generic
    "domain is free", // generic
    "this query returned 0 objects", // afilias 风格
    "domain name has not been registered", // hk 完整表述
  ];

  protected function getUnregisteredRegExp()
  {
    return "/" . implode("|", self::UNREGISTERED_KEYWORDS) . "/i";
  }

  protected function getUnregistered()
  {
    return preg_match($this->getUnregisteredRegExp(), $this->data);
  }

  protected function getBaseRegExp($pattern)
  {
    // 字段名内部的空格匹配任意数量的空白（含制表符），并容忍冒号前的空白。
    // 许多国别域名（ccTLD）registry 采用“按列对齐”的输出格式，字段名内会出现
    // 多个空格，例如 nic.md 的 "Domain  name:"（Domain 与 name 之间为两个空格）。
    // 原正则要求字段名与冒号严格相连且内部为单空格，导致这类字段整体匹配失败
    // （域名、状态等显示为空）。这里将关键词中的空格替换为 [\t ]+ 使匹配更健壮。
    $pattern = str_replace(" ", "[\\t ]+", $pattern);

    return "/^[\t ]*(?:$pattern)[\t ]*:(.+)$/im";
  }

  private const DOMAIN_KEYWORDS = [
    "domain name", // com
    "domain", // ar
    "dominio", // cu
    "domainname", // lu
    "domain name \(utf8\)", // укр
    "domain-name", // 连字符写法
    "nom de domaine", // fr 变体
  ];

  protected function getDomainRegExp()
  {
    return $this->getBaseRegExp(implode("|", self::DOMAIN_KEYWORDS));
  }

  protected function getDomain()
  {
    if (preg_match($this->getDomainRegExp(), $this->data, $matches)) {
      $domain = strtolower(explode(" ", trim($matches[1]))[0]);
      if (!empty($domain)) {
        return idn_to_utf8($domain);
      }
    }

    return "";
  }

  private const REGISTRAR_KEYWORDS = [
    "registrar", // com
    "registrar name", // ae
    "sponsoring registrar", // cn
    "sponsoring registrar organization", // id
    "current registar", // kz
    "registrar-name", // lu
    "registration service provider", // tw
    "registered by", // ac.uk
  ];

  protected function getRegistrarRegExp()
  {
    return $this->getBaseRegExp(implode("|", self::REGISTRAR_KEYWORDS));
  }

  protected function getRegistrar()
  {
    if (preg_match($this->getRegistrarRegExp(), $this->data, $matches)) {
      return trim($matches[1]);
    }

    return "";
  }

  private const REGISTRAR_URL_KEYWORDS = [
    "registrar url", // com
    "sponsoring registrar url", // id
    "registrar website", // lt
    "registrar-url", // lu
    "registration service url", // tw
  ];

  protected function getRegistrarURLRegExp()
  {
    return $this->getBaseRegExp(implode("|", self::REGISTRAR_URL_KEYWORDS));
  }

  protected function getRegistrarURL()
  {
    if (preg_match($this->getRegistrarURLRegExp(), $this->data, $matches)) {
      $url = trim($matches[1]);

      if (!empty($url) && !preg_match("/^https?:\/\//i", $url)) {
        return "http://$url";
      }

      return $url;
    }

    return "";
  }

  private const CREATION_DATE_KEYWORDS = [
    "creation date", // com
    "registered", // am
    "created", // br
    "registration time", // cn
    "submission date", // gw
    "domain name commencement date", // hk
    "domain creation date", // hm
    "record created", // hu
    "created on", // id
    "assigned", // il
    "first registered date", // np
    "registered date", // kr
    "registered on", // ro
    "registration date", // rs
    "activation", // tg
    "created date", // th
    "domain registration date", // 通用
    "registration date time", // 通用
    "record created on", // 通用
    "creation time", // 通用
    "registered at", // 通用
  ];

  protected function getCreationDateRegExp()
  {
    return $this->getBaseRegExp(implode("|", self::CREATION_DATE_KEYWORDS));
  }

  protected function getCreationDate()
  {
    if (preg_match($this->getCreationDateRegExp(), $this->data, $matches)) {
      return trim($matches[1]);
    }

    return "";
  }

  protected function getCreationDateISO8601()
  {
    return $this->getISO8601($this->creationDate);
  }

  private const EXPIRATION_DATE_KEYWORDS = [
    "registry expiry date", // com
    "expires", // am
    "expire", // ar
    "expiration date", // bn
    "expiration time", // cn
    "expiry date", // fr
    "domain expiration date", // hm
    "registrar registration expiration date", // hr
    "validity", // il
    "expire date", // it
    "expires on", // jp
    "record expires on", // kg
    "renewal date", // pl
    "paid-till", // ru
    "valid until", // sk
    "expiration", // tg
    "exp date", // th
    "expiry", // tm
    "expiration date time", // 通用
    "domain expiration date", // 通用
    "expire time", // 通用
    "expires on date", // 通用
    "valid till", // 通用
    "valid-date", // 通用
    "registrar registration expiration date", // gTLD
  ];

  protected function getExpirationDateRegExp()
  {
    return $this->getBaseRegExp(implode("|", self::EXPIRATION_DATE_KEYWORDS));
  }

  protected function getExpirationDate()
  {
    if (preg_match($this->getExpirationDateRegExp(), $this->data, $matches)) {
      return trim($matches[1]);
    }

    return "";
  }

  protected function getExpirationDateISO8601()
  {
    return $this->getISO8601($this->expirationDate);
  }

  protected const UPDATED_DATE_KEYWORDS = [
    "updated date", // com
    "last modified", // am
    "changed", // ar
    "modified", // ax
    "modified date", // bn
    "update date", // by
    "last-update", // fr
    "last updated on", // id
    "last update", // it
    "last updated", // jp
    "record last updated on", // kg
    "last updated date", // np
    "lastmod", // co.pl
    "modification date", // rs
    "updated", // sk
    "last edited on", // to
  ];

  protected function getUpdatedDateRegExp()
  {
    return $this->getBaseRegExp(implode("|", self::UPDATED_DATE_KEYWORDS));
  }

  protected function getUpdatedDate()
  {
    if (preg_match($this->getUpdatedDateRegExp(), $this->data, $matches)) {
      return trim($matches[1]);
    }

    return "";
  }

  protected function getUpdatedDateISO8601()
  {
    return $this->getISO8601($this->updatedDate);
  }

  private const AVAILABLE_DATE_KEYWORDS = [
    "available", // ax
    "date_to_release", // nu
    "free-date", // ru
  ];

  protected function getAvailableDateRegExp()
  {
    return $this->getBaseRegExp(implode("|", self::AVAILABLE_DATE_KEYWORDS));
  }

  protected function getAvailableDate()
  {
    $regExp = $this->getAvailableDateRegExp();

    if ($regExp && preg_match($regExp, $this->data, $matches)) {
      return trim($matches[1]);
    }

    return "";
  }

  protected function getAvailableDateISO8601()
  {
    return $this->getISO8601($this->availableDate);
  }

  protected function getISO8601($dateString, $format = null)
  {
    if (empty($dateString)) {
      return null;
    }

    if (empty($format)) {
      $format = $this->dateFormat;
    }

    // 仅在“无显式格式”的通用路径上做不规则日期清洗；带 $dateFormat 的解析器
    // 走 createFromFormat 精确匹配，清洗会破坏其固定格式，故保持原样。
    if (empty($format)) {
      $dateString = $this->normalizeDateString($dateString);
    }

    try {
      $hasTime = preg_match("/\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?/", $dateString);

      $timezone = new DateTimeZone($hasTime ? $this->timezone : "UTC");

      $date = empty($format)
        ? new DateTime($dateString, $timezone)
        : DateTime::createFromFormat($format, $dateString, $timezone);

      if ($date === false) {
        return null;
      }

      $date->setTimezone(new DateTimeZone("UTC"));

      return $date->format($hasTime ? "Y-m-d\TH:i:s\Z" : "Y-m-d");
    } catch (Throwable $e) {
      return null;
    }
  }

  // 归一化各类国别域名（ccTLD）不规则日期写法，尽量提升可解析率。
  // 设计原则：只做“无歧义”的安全转换，模糊输入保持原样交给 DateTime 兜底，
  // 解析失败最终返回 null（与原行为一致，不会产生错误日期）。
  protected function normalizeDateString($s)
  {
    $s = trim((string) $s);
    if ($s === "") {
      return $s;
    }

    // 1) 去除括号内注释，如 "2024-01-01 (registry grace)" / "（UTC）"
    $s = preg_replace('/[（(][^）)]*[）)]/u', ' ', $s);

    // 2) 去除多余描述词与前缀，如 "before 2025-01-01" / "on 2024.." 
    $s = preg_replace('/^(before|on|at|since|至|到|于)\s+/iu', '', trim($s));

    // 3) 常见非标准时区缩写（DateTime 无法识别）→ 去掉，按注册局本地时区处理
    $s = preg_replace('/\b(CLST|CLT|BRT|BRST|MSK|JST|KST|IST|EET|EEST|CET|CEST|WET)\b/i', '', $s);

    // 4) 将 "yyyy.mm.dd" / "yyyy/mm/dd" 统一为 ISO 短横线
    if (preg_match('/^(\d{4})[.\/](\d{1,2})[.\/](\d{1,2})\b(.*)$/', $s, $m)) {
      $s = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) . $m[4];
    }
    // 5) 将 "dd.mm.yyyy" / "dd-mm-yyyy" / "dd/mm/yyyy" 转 ISO。
    //    仅当首段 > 12（必为“日”，无歧义）时转换，避免误判月/日顺序。
    elseif (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})\b(.*)$/', $s, $m)) {
      if ((int) $m[1] > 12 && (int) $m[2] <= 12) {
        $s = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) . $m[4];
      }
    }

    // 6) 折叠多余空白
    return trim(preg_replace('/\s{2,}/', ' ', $s));
  }

  protected function getDateDiffText($start, $end)
  {
    if (empty($start) || empty($end)) {
      return "";
    }

    try {
      $timezone = new DateTimeZone("UTC");

      $startDate = new DateTime($start, $timezone);
      $endDate = new DateTime($end, $timezone);
      $interval = $startDate->diff($endDate);

      $parts = [];
      if ($interval->y) {
        $parts[] = "{$interval->y}Y";
      }
      if ($interval->m) {
        $parts[] = "{$interval->m}Mo";
      }
      if ($interval->d) {
        $parts[] = "{$interval->d}D";
      }

      return ($interval->invert ? "-" : "") . ($parts ? implode(" ", $parts) : "0D");
    } catch (Throwable $e) {
      return "";
    }
  }

  protected function getDateDiffSeconds($start, $end)
  {
    if (empty($start) || empty($end)) {
      return null;
    }

    try {
      $timezone = new DateTimeZone("UTC");

      $startDate = new DateTime($start, $timezone);
      $endDate = new DateTime($end, $timezone);

      return $endDate->getTimestamp() - $startDate->getTimestamp();
    } catch (Throwable $e) {
      return null;
    }
  }

  private const STATUS_KEYWORDS = [
    "domain status", // com
    "status", // ae
    "registration status", // bg
    "registry status", // укр
    "domain state", // md
    "eppstatus", // fr / re / pm / yt / tf / wf（AFNIC：EPP 锁定状态单列于 eppstatus 行）
  ];

  protected const STATUS_MAP = [
    "addperiod" => "addPeriod",
    "autorenewperiod" => "autoRenewPeriod",
    "inactive" => "inactive",
    "ok" => "ok",
    "active" => "ok",
    "pendingcreate" => "pendingCreate",
    "pendingdelete" => "pendingDelete",
    "pendingrenew" => "pendingRenew",
    "pendingrestore" => "pendingRestore",
    "pendingtransfer" => "pendingTransfer",
    "pendingupdate" => "pendingUpdate",
    "redemptionperiod" => "redemptionPeriod",
    "renewperiod" => "renewPeriod",
    "serverdeleteprohibited" => "serverDeleteProhibited",
    "serverhold" => "serverHold",
    "serverrenewprohibited" => "serverRenewProhibited",
    "servertransferprohibited" => "serverTransferProhibited",
    "serverupdateprohibited" => "serverUpdateProhibited",
    "transferperiod" => "transferPeriod",
    "clientdeleteprohibited" => "clientDeleteProhibited",
    "clienthold" => "clientHold",
    "clientrenewprohibited" => "clientRenewProhibited",
    "clienttransferprohibited" => "clientTransferProhibited",
    "clientupdateprohibited" => "clientUpdateProhibited",
  ];

  protected function getStatusRegExp()
  {
    return $this->getBaseRegExp(implode("|", self::STATUS_KEYWORDS));
  }

  protected function getStatus()
  {
    if (preg_match_all($this->getStatusRegExp(), $this->data, $matches)) {
      $result = [];
      $seen = [];

      foreach (array_filter(array_map("trim", $matches[1])) as $item) {
        // 标准 EPP 格式："statusCode https://icann.org/epp#statusCode"
        if (preg_match("/^[a-z]+ https?:\/\/.+/i", $item, $m)) {
          $parts = explode(" ", $item, 2);
          $this->pushStatus($result, $seen, $parts[0], $parts[1]);
          continue;
        }

        // 不规则 ccTLD 格式：同一行含多个以空白分隔的状态词
        // （如 nic.md 的 "Inactive RenewProhibited RedemptionPeriod"）。
        // 当整行仅由多个“纯单词 token”组成（字母/数字，无描述性文字或标点）时，
        // 拆分为独立状态，便于逐个着色与翻译；否则保持整体不变。
        $tokens = preg_split('/[\t ]+/', $item);
        $allWordTokens = count($tokens) > 1;
        foreach ($tokens as $tk) {
          if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $tk)) { $allWordTokens = false; break; }
        }
        if ($allWordTokens) {
          foreach ($tokens as $tk) { $this->pushStatus($result, $seen, $tk, ""); }
          continue;
        }

        $this->pushStatus($result, $seen, $item, "");
      }

      return $result;
    }

    return [];
  }

  // 追加一个状态项并按（小写）去重，避免同一状态重复出现
  private function pushStatus(array &$result, array &$seen, string $text, string $url): void
  {
    $text = trim($text);
    if ($text === "") { return; }
    $key = strtolower($text);
    if (isset($seen[$key])) { return; }
    $seen[$key] = true;
    $result[] = ["text" => $text, "url" => $url];
  }

  protected function getStatusFromExplode($separator)
  {
    if (preg_match($this->getStatusRegExp(), $this->data, $matches)) {
      return array_map(
        fn($item) => ["text" => $item, "url" => ""],
        array_filter(array_map("trim", explode($separator, $matches[1]))),
      );
    }

    return [];
  }

  private function setStatusUrl()
  {
    array_walk($this->status, function (&$item) {
      $key = str_replace(" ", "", strtolower($item["text"]));
      if (isset(self::STATUS_MAP[$key])) {
        $value = self::STATUS_MAP[$key];
        $item["text"] = $value;
        $item["url"] = "https://icann.org/epp#$value";
      }
    });
  }

  private const NAME_SERVERS_KEYWORDS = [
    "name server", // com
    "nserver", // ar
    "nameserver", // gf
    "name server \(db\)", // tg
    "name servers", // 复数写法
    "dns server", // 通用
    "dns servers", // 通用
    "nameservers", // 通用
    "domain nameservers", // 通用
  ];

  protected function getNameServersRegExp()
  {
    return $this->getBaseRegExp(implode("|", self::NAME_SERVERS_KEYWORDS));
  }

  protected function getNameServers()
  {
    if (preg_match_all($this->getNameServersRegExp(), $this->data, $matches)) {
      return array_map(
        fn($item) => strtolower(explode(" ", $item)[0]),
        array_unique(array_filter(array_map("trim", $matches[1]))),
      );
    }

    return [];
  }

  protected function getNameServersFromExplode($separator)
  {
    if (preg_match($this->getNameServersRegExp(), $this->data, $matches)) {
      return array_map(
        fn($item) => strtolower(explode(" ", $item)[0]),
        array_unique(array_filter(array_map("trim", explode($separator, $matches[1])))),
      );
    }

    return [];
  }

  private const DNSSEC_KEYWORDS = [
    "dnssec", // com
    "dnssec status", // generic
    "dnssec signed", // generic
    "signing key", // generic
  ];

  // 归一化为 signed / unsigned / ""（未知）。
  private const DNSSEC_SIGNED_VALUES = [
    "signed",
    "signeddelegation",
    "signed delegation",
    "yes",
    "active",
    "true",
    "enabled",
  ];

  private const DNSSEC_UNSIGNED_VALUES = [
    "unsigned",
    "unsigneddelegation",
    "unsigned delegation",
    "no",
    "inactive",
    "false",
    "disabled",
    "no dnssec",
    "not signed",
  ];

  protected function getDNSSECRegExp()
  {
    return $this->getBaseRegExp(implode("|", self::DNSSEC_KEYWORDS));
  }

  protected function getDNSSEC()
  {
    if (preg_match($this->getDNSSECRegExp(), $this->data, $matches)) {
      $value = strtolower(trim($matches[1]));

      if ($value === "") {
        return "";
      }

      if (in_array($value, self::DNSSEC_UNSIGNED_VALUES, true)) {
        return "unsigned";
      }

      if (in_array($value, self::DNSSEC_SIGNED_VALUES, true)) {
        return "signed";
      }

      // 出现 DS/DNSKEY 记录等信息通常代表已签名
      if (strpos($value, "signeddelegation") !== false || strpos($value, "ds ") !== false) {
        return "signed";
      }
    }

    return "";
  }

  protected const GRACE_PERIOD_KEYWORDS = [
    "autoRenewPeriod", // com
  ];

  protected const REDEMPTION_PERIOD_KEYWORDS = [
    "redemptionPeriod", // com
  ];

  protected const PENDING_DELETE_KEYWORDS = [
    "pendingDelete", // com
  ];

  protected function hasKeywordInStatus($keywords)
  {
    $texts = array_map("strtolower", array_column($this->status, "text"));
    $keywords = array_map("strtolower", $keywords);

    return !empty(array_intersect($texts, $keywords));
  }

  private const EMPTY_PROPERTIES = [
    "domain",
    "registrar",
    "registrarURL",
    "creationDate",
    "expirationDate",
    "updatedDate",
    "availableDate",
    "status",
    "nameServers"
  ];

  private const EMPTY_VALUES = [
    "http://registrarurl", // bf
    "http://null", // ml
    "none", // nc
    "<no", // uz
    "-", // uz
    "not", // uz
    "not.defined." // uz
  ];

  protected function removeEmptyValues()
  {
    foreach (self::EMPTY_PROPERTIES as $property) {
      $value = $this->$property;

      if (empty($value)) {
        continue;
      }

      switch ($property) {
        case "status":
          $this->status = array_filter(
            $value,
            fn($item) => !in_array(strtolower($item["text"]), self::EMPTY_VALUES)
          );
          break;
        case "nameServers":
          $this->nameServers = array_diff(
            array_map("strtolower", $value),
            self::EMPTY_VALUES
          );
          break;
        default:
          if (in_array(strtolower($value), self::EMPTY_VALUES)) {
            $this->$property = "";
          }
          break;
      }
    }
  }

  public function getUnknown()
  {
    return empty($this->registrar) &&
      empty($this->creationDate) &&
      empty($this->expirationDate) &&
      empty($this->updatedDate) &&
      empty($this->availableDate) &&
      empty($this->status) &&
      empty($this->nameServers);
  }
}
