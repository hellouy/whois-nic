<?php
class ParserRDAP extends Parser
{
  private $json = [];

  public function __construct($code, $data, $json)
  {
    $this->rdapData = $data;
    $this->json = $json;

    $this->registered = $code !== 404;
    if (!$this->registered) {
      return;
    }

    if (empty($this->rdapData)) {
      $this->unknown = true;
      return;
    }

    $this->getDomain();

    $this->getRegistrar();

    $this->getDate();

    $this->getStatus();

    $this->nameServers = $this->getNameServers();

    $this->getDNSSEC();

    // 扩展字段提取：注册商详情 / 联系人（RDAP JSON 路径）
    $this->extractExtended();

    $this->age = $this->getDateDiffText($this->creationDateISO8601, "now");
    $this->ageSeconds = $this->getDateDiffSeconds($this->creationDateISO8601, "now");
    $this->remaining = $this->getDateDiffText("now", $this->expirationDateISO8601);
    $this->remainingSeconds = $this->getDateDiffSeconds("now", $this->expirationDateISO8601);

    $this->gracePeriod = $this->hasKeywordInStatus(self::GRACE_PERIOD_KEYWORDS);
    $this->redemptionPeriod = $this->hasKeywordInStatus(self::REDEMPTION_PERIOD_KEYWORDS);
    $this->pendingDelete = $this->hasKeywordInStatus(self::PENDING_DELETE_KEYWORDS);

    $this->unknown = $this->getUnknown();
    if ($this->unknown) {
      $this->registered = false;
    }
  }

  protected function getDomain()
  {
    // 优先 ldhName（ASCII/punycode），缺失时回落 unicodeName（部分注册局只给这个）
    $name = "";
    if (!empty($this->json["ldhName"]) && is_string($this->json["ldhName"])) {
      $name = strtolower(trim($this->json["ldhName"]));
    } elseif (!empty($this->json["unicodeName"]) && is_string($this->json["unicodeName"])) {
      $name = strtolower(trim($this->json["unicodeName"]));
    }

    if ($name === "") {
      return;
    }

    // idn_to_utf8 对非 punycode 或异常输入可能返回 false，需回落原值
    $utf8 = idn_to_utf8($name);
    $this->domain = ($utf8 !== false) ? $utf8 : $name;
  }

  protected function getRegistrar()
  {
    if (empty($this->json["entities"])) {
      return;
    }

    foreach ($this->json["entities"] as $entity) {
      $roles = $entity["roles"] ?? [];

      if (
        (is_string($roles) && $roles === "registrar") ||
        (is_array($roles) && in_array("registrar", $roles))
      ) {
        // 1) 优先从 registrar 实体自身的 vcard 读取 fn/org 与 url
        [$name, $url] = $this->extractVcardNameUrl($entity);

        // 2) 名称缺失时，递归查子实体的 vcard fn/org。
        //    部分注册局（如 KENIC/.ke）把注册商名放在嵌套的 abuse/technical
        //    子实体里，顶层 registrar 实体只有一个 handle（如 "VIC2"）。
        if ($name === "" && !empty($entity["entities"])) {
          $name = $this->findVcardNameInEntities($entity["entities"]);
        }

        // 3) 仍无名称时，才退回 handle（ar, cz 等）
        if ($name === "" && !empty($entity["handle"])) {
          $name = $entity["handle"];
        }

        $this->registrar = $name;
        if ($url !== "") {
          $this->registrarURL = $url;
        }

        if (empty($this->registrarURL)) {
          if (!empty($entity["links"])) {
            foreach ($entity["links"] as $link) {
              if (
                !empty($link["title"]) &&
                !empty($link["href"]) &&
                $link["title"] === "Registrar's Website"
              ) {
                $this->registrarURL = $this->formatURL($link["href"]);
                break;
              }
            }
          } else if (!empty($entity["url"])) {
            $this->registrarURL = $this->formatURL($entity["url"]);
          }
        }

        break;
      }
    }
  }

  // 兼容 vcardArray 的两种形态：["elements" => [...]] 或 ["vcard", [...]]
  private function getVcardElements($entity)
  {
    if (empty($entity["vcardArray"])) {
      return [];
    }

    return $entity["vcardArray"]["elements"] ?? ($entity["vcardArray"][1] ?? []);
  }

  // 从单个实体的 vcard 提取 [名称(fn 优先，其次 org), url]
  private function extractVcardNameUrl($entity)
  {
    $name = "";
    $url = "";

    foreach ($this->getVcardElements($entity) as $item) {
      if (!is_array($item) || !isset($item[0])) {
        continue;
      }

      switch ($item[0]) {
        case "fn":
        case "org":
          if ($name === "" && !empty($item[3]) && is_string($item[3])) {
            $name = trim($item[3]);
          }
          break;
        case "url":
          if ($url === "" && !empty($item[3]) && is_string($item[3])) {
            $url = $this->formatURL($item[3]);
          }
          break;
      }
    }

    return [$name, $url];
  }

  // 递归在子实体中查找第一个可用的 vcard fn/org 名称
  private function findVcardNameInEntities($entities)
  {
    if (!is_array($entities)) {
      return "";
    }

    foreach ($entities as $sub) {
      if (!is_array($sub)) {
        continue;
      }

      [$name] = $this->extractVcardNameUrl($sub);
      if ($name !== "") {
        return $name;
      }

      if (!empty($sub["entities"])) {
        $deep = $this->findVcardNameInEntities($sub["entities"]);
        if ($deep !== "") {
          return $deep;
        }
      }
    }

    return "";
  }

  private function formatURL($url)
  {
    if (empty($url)) {
      return "";
    }

    return preg_match("/^https?:\/\//i", $url) ? $url : "http://" . $url;
  }

  protected const EXPIRATION_DATE_KEYWORDS = [
    "expiration", // com
    "soft expiration", // is
    "record expires", // kg
  ];

  protected function getDate()
  {
    if (empty($this->json["events"])) {
      return;
    }

    foreach ($this->json["events"] as $event) {
      if (!empty($event["eventDate"])) {
        $action = strtolower($event["eventAction"]);
        if ($action === "registration") {
          $this->creationDate = $event["eventDate"];
          $this->creationDateISO8601 = $this->getCreationDateISO8601();
        } else if (in_array($action, self::EXPIRATION_DATE_KEYWORDS)) {
          $this->expirationDate = $event["eventDate"];
          $this->expirationDateISO8601 = $this->getExpirationDateISO8601();
        } else if ($action === "last changed") {
          $this->updatedDate = $event["eventDate"];
          $this->updatedDateISO8601 = $this->getUpdatedDateISO8601();
        }
      }
    }
  }

  protected function getStatus()
  {
    if (empty($this->json["status"])) {
      return;
    }

    $this->status = array_map(
      function ($item) {
        $key = str_replace(" ", "", strtolower($item));
        if (isset(self::STATUS_MAP[$key])) {
          $value = self::STATUS_MAP[$key];
          return ["text" => $value, "url" => "https://icann.org/epp#$value"];
        }

        return ["text" => $item, "url" => ""];
      },
      $this->json["status"],
    );
  }

  protected function getNameServers()
  {
    if (empty($this->json["nameservers"])) {
      return;
    }

    $servers = [];
    foreach ($this->json["nameservers"] as $item) {
      if (!is_array($item)) {
        continue;
      }

      // 部分注册局的 nameserver 对象缺少 ldhName，只提供 unicodeName
      $name = "";
      if (!empty($item["ldhName"]) && is_string($item["ldhName"])) {
        $name = $item["ldhName"];
      } elseif (!empty($item["unicodeName"]) && is_string($item["unicodeName"])) {
        $name = $item["unicodeName"];
      }

      $host = strtolower(explode(" ", trim($name))[0]);
      if ($host === "") {
        continue;
      }

      $utf8 = idn_to_utf8($host);
      $servers[] = ($utf8 !== false) ? $utf8 : $host;
    }

    $this->nameServers = array_values(array_unique($servers));
  }

  protected function getDNSSEC()
  {
    if (empty($this->json["secureDNS"]) || !is_array($this->json["secureDNS"])) {
      return;
    }

    $secureDNS = $this->json["secureDNS"];

    // RDAP 权威字段：delegationSigned 明确表示是否已签名委派
    if (array_key_exists("delegationSigned", $secureDNS)) {
      $this->dnssec = $secureDNS["delegationSigned"] ? "signed" : "unsigned";
      return;
    }

    // 无 delegationSigned 时，存在 DS/Key 记录也视为已签名
    if (!empty($secureDNS["dsData"]) || !empty($secureDNS["keyData"])) {
      $this->dnssec = "signed";
    }
  }

  // ==================== 扩展字段提取（RDAP JSON 路径） ====================
  // 覆盖基类的 extractExtended()：从 RDAP 的 entities / vcardArray 中提取
  // 注册商 IANA ID、地址、滥用联系，以及 registrant / administrative / technical
  // 联系人的姓名、组织、邮箱、电话、国家等信息。

  // 递归查找指定 role 的实体（registrar 顶层、abuse 常为其子实体）
  private function findRdapEntity($entities, $role)
  {
    if (!is_array($entities)) {
      return null;
    }
    foreach ($entities as $e) {
      if (!is_array($e)) {
        continue;
      }
      $roles = $e["roles"] ?? [];
      if ((is_array($roles) && in_array($role, $roles, true)) || $roles === $role) {
        return $e;
      }
      if (!empty($e["entities"])) {
        $sub = $this->findRdapEntity($e["entities"], $role);
        if ($sub) {
          return $sub;
        }
      }
    }
    return null;
  }

  private function rdapVcardElements($entity)
  {
    if (empty($entity["vcardArray"])) {
      return [];
    }
    return $entity["vcardArray"]["elements"] ?? ($entity["vcardArray"][1] ?? []);
  }

  private function rdapVcardGet($els, $key)
  {
    if (!is_array($els)) {
      return null;
    }
    foreach ($els as $it) {
      if (is_array($it) && ($it[0] ?? '') === $key) {
        return $it;
      }
    }
    return null;
  }

  // 从单个 RDAP 实体的 vcard 提取联系人信息：
  // 返回 [姓名(fn), 组织(org), 邮箱, 电话, 国家, 城市, 州省]
  private function rdapVcardContact($entity)
  {
    $els = $this->rdapVcardElements($entity);
    $pick = function ($key) use ($els) {
      $it = $this->rdapVcardGet($els, $key);
      return ($it && isset($it[3]) && is_string($it[3])) ? trim($it[3]) : '';
    };
    $keep = function ($v) {
      return $this->isPlaceholderValue($v) ? '' : $v;
    };
    $name = $keep($pick('fn'));
    $org = $keep($pick('org'));
    $email = $this->cleanEmailValue($pick('email'));
    $phone = $this->cleanPhoneValue($pick('tel'));
    // vcard adr 结构化数组：[信箱, 扩展, 街道, 城市, 州省, 邮编, 国家]
    $country = $city = $state = '';
    $adr = $this->rdapVcardGet($els, 'adr');
    if ($adr && isset($adr[3]) && is_array($adr[3])) {
      $p = $adr[3];
      $city = isset($p[3]) && is_string($p[3]) ? trim($p[3]) : '';
      $state = isset($p[4]) && is_string($p[4]) ? trim($p[4]) : '';
      $country = isset($p[6]) && is_string($p[6]) ? trim($p[6]) : '';
      if ($country === '') {
        $last = end($p);
        if (is_string($last)) {
          $country = trim($last);
        }
      }
    }
    return [$name, $org, $email, $phone, $keep($country), $keep($city), $keep($state)];
  }

  // 注册商 IANA ID：从 registrar 实体的 publicIds 中提取（type 含 IANA）
  private function rdapRegistrarIanaId($entity)
  {
    if (empty($entity["publicIds"]) || !is_array($entity["publicIds"])) {
      return '';
    }
    foreach ($entity["publicIds"] as $pid) {
      if (isset($pid["identifier"]) && preg_match('/iana/i', $pid["type"] ?? '')) {
        return trim((string) $pid["identifier"]);
      }
    }
    return '';
  }

  // 注册商地址：优先 vcard adr 的 label 参数，其次 7 段结构化数组
  private function rdapRegistrarAddress($entity)
  {
    $adr = $this->rdapVcardGet($this->rdapVcardElements($entity), 'adr');
    if (!$adr) {
      return '';
    }
    if (!empty($adr[1]["label"]) && is_string($adr[1]["label"])) {
      return trim(preg_replace('/\s*\R\s*/', ' · ', $adr[1]["label"]));
    }
    if (isset($adr[3]) && is_array($adr[3])) {
      return implode(' · ', array_filter(
        array_map(fn($v) => is_string($v) ? trim($v) : '', $adr[3]),
        fn($v) => $v !== ''
      ));
    }
    return '';
  }

  protected function extractExtended()
  {
    // 顶层 handle 即注册局域名的 Registry Domain ID
    if ($this->registryDomainId === '' && !empty($this->json["handle"]) && is_string($this->json["handle"])) {
      $this->registryDomainId = trim($this->json["handle"]);
    }

    // RDAP 顶层 port43 字段即 WHOIS 服务器地址
    if ($this->whoisServer === '' && !empty($this->json["port43"]) && is_string($this->json["port43"])) {
      $this->whoisServer = trim($this->json["port43"]);
    }

    if (empty($this->json["entities"])) {
      $this->detectDnsProvider();
      return;
    }

    // 注册商实体：IANA ID / 地址 / 滥用联系
    $registrarEntity = $this->findRdapEntity($this->json["entities"], 'registrar');
    if ($registrarEntity) {
      if ($this->registrarIanaId === '') {
        $this->registrarIanaId = $this->rdapRegistrarIanaId($registrarEntity);
      }
      if ($this->registrarAddress === '') {
        $this->registrarAddress = $this->rdapRegistrarAddress($registrarEntity);
      }
      // 滥用联系（registrar 的 abuse 子实体 vcard email/tel）
      if ($this->abuseEmail === '' || $this->abusePhone === '') {
        $abuseEntity = !empty($registrarEntity["entities"])
          ? $this->findRdapEntity($registrarEntity["entities"], 'abuse')
          : null;
        if ($abuseEntity) {
          $aEls = $this->rdapVcardElements($abuseEntity);
          if ($this->abuseEmail === '') {
            $em = $this->rdapVcardGet($aEls, 'email');
            if ($em && isset($em[3])) {
              $this->abuseEmail = $this->cleanEmailValue(trim((string) $em[3]));
            }
          }
          if ($this->abusePhone === '') {
            $tel = $this->rdapVcardGet($aEls, 'tel');
            if ($tel && isset($tel[3])) {
              $this->abusePhone = $this->cleanPhoneValue(trim((string) $tel[3]));
            }
          }
        }
      }
    }

    // 通用：将 RDAP 实体联系人映射到 Parser 字段（仅在字段仍为空时填充）
    $applyContact = function ($entity, $prefix) {
      if (!$entity) {
        return;
      }
      [$name, $org, $email, $phone, $country, $city, $state] = $this->rdapVcardContact($entity);
      if ($this->{$prefix . 'Name'} === '' && $name !== '') {
        $this->{$prefix . 'Name'} = $name;
      }
      if ($this->{$prefix . 'Org'} === '' && $org !== '') {
        $this->{$prefix . 'Org'} = $org;
      }
      if ($this->{$prefix . 'Email'} === '' && $email !== '') {
        $this->{$prefix . 'Email'} = $email;
      }
      if ($this->{$prefix . 'Phone'} === '' && $phone !== '') {
        $this->{$prefix . 'Phone'} = $phone;
      }
      if ($this->{$prefix . 'Country'} === '' && $country !== '') {
        $this->{$prefix . 'Country'} = $country;
      }
      if ($prefix === 'registrant') {
        if ($this->registrantCity === '' && $city !== '') {
          $this->registrantCity = $city;
        }
        if ($this->registrantState === '' && $state !== '') {
          $this->registrantState = $state;
        }
      }
    };

    $applyContact($this->findRdapEntity($this->json["entities"], 'registrant'), 'registrant');
    $applyContact($this->findRdapEntity($this->json["entities"], 'administrative'), 'admin');
    $applyContact($this->findRdapEntity($this->json["entities"], 'technical'), 'tech');

    $this->detectDnsProvider();
  }
}
