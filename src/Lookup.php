<?php

use Pdp\Domain;
use Pdp\Rules;

class Lookup
{
  public $domain;

  public $extension;

  private $extensionTop;

  private $dataSource = [];

  public $whoisData;

  private $whoisParser;

  private $whoisUnknown;

  private $whoisError;

  public $rdapData;

  private $rdapParser;

  private $rdapUnknown;

  private $rdapError;

  public $parser;

  public function __construct($domain, $dataSource)
  {
    $this->parseDomain($domain);
    $this->dataSource = $dataSource;

    $wantWhois = in_array("whois", $dataSource, true);
    $wantRdap = in_array("rdap", $dataSource, true);

    if ($wantWhois && $wantRdap) {
      // 同时需要两者：并行执行，总耗时取较慢者而非相加，显著提速。
      $this->getWhoisAndRdapParallel();
      $this->merge();
    } elseif ($wantWhois) {
      $this->getWHOIS();
    } elseif ($wantRdap) {
      $this->getRDAP();
    }
  }

  // 并行获取 WHOIS 与 RDAP：RDAP 走 curl_multi，WHOIS 走非阻塞 socket，
  // 二者在同一 stream/curl 事件循环里并进，避免串行等待两次超时。
  // 任一路径失败都会被各自的 try/catch 收敛为 error/unknown，交由 merge() 兜底。
  private function getWhoisAndRdapParallel()
  {
    // 1) 准备 RDAP：curl_multi handle。失败（无服务器等）时回退串行。
    $mh = null;
    $rdapHandle = null;
    $rdap = null;
    try {
      $rdap = new RDAP($this->domain, $this->extension, $this->extensionTop);
      $rdapHandle = $rdap->buildHandle();
      $mh = curl_multi_init();
      curl_multi_add_handle($mh, $rdapHandle);
      curl_multi_exec($mh, $running); // 触发首次执行
    } catch (Exception $e) {
      if ($mh && $rdapHandle) {
        curl_multi_remove_handle($mh, $rdapHandle);
      }
      if ($rdapHandle) {
        curl_close($rdapHandle);
      }
      if ($mh) {
        curl_multi_close($mh);
      }
      $mh = null;
      $rdapHandle = null;
      $this->captureRdapUnknownOrError($e);
    }

    // 2) 准备 WHOIS：非 web 后缀用非阻塞 socket 并入循环；web 抓取无法并入，
    //    留待循环后单独处理（此类后缀较少，不影响主流程提速）。
    $whois = null;
    $whoisSocket = null;
    $whoisWebFallback = false;
    try {
      $whois = new WHOIS($this->domain, $this->extension, $this->extensionTop);
      if ($whois->isWeb()) {
        $whoisWebFallback = true;
      } else {
        $whoisSocket = $whois->openSocket();
      }
    } catch (Exception $e) {
      $whois = null;
      $this->captureWhoisUnknownOrError($e);
    }

    // 3) 统一事件循环：并进推进 RDAP(curl_multi) 与 WHOIS(socket 读取)。
    $whoisData = "";
    $whoisDone = ($whoisSocket === null);
    $whoisIdleTimeout = 1.5;
    $whoisLastActivity = microtime(true);
    $whoisHardDeadline = microtime(true) + 6;
    $whoisTimedOut = false;

    $rdapDone = ($mh === null);

    while (!$rdapDone || !$whoisDone) {
      // -- 推进 RDAP --
      if (!$rdapDone) {
        curl_multi_exec($mh, $running);
        if ($running == 0) {
          $rdapDone = true;
        }
      }

      // -- 推进 WHOIS socket --
      if (!$whoisDone) {
        if (microtime(true) >= $whoisHardDeadline) {
          $whoisTimedOut = ($whoisData === "");
          $whoisDone = true;
        } else {
          $read = [$whoisSocket];
          $write = null;
          $except = null;
          $ready = @stream_select($read, $write, $except, 0, 100000); // 100ms
          if ($ready === false) {
            $whoisDone = true;
          } elseif ($ready > 0) {
            $chunk = fread($whoisSocket, 8192);
            if ($chunk === false) {
              $whoisDone = true;
            } else {
              if ($chunk !== "") {
                $whoisData .= $chunk;
                $whoisLastActivity = microtime(true);
              }
              if (feof($whoisSocket)) {
                $whoisDone = true;
              }
            }
          } else {
            if ($whoisData !== "" && (microtime(true) - $whoisLastActivity) >= $whoisIdleTimeout) {
              $whoisDone = true;
            }
          }
        }
      }

      // 若 RDAP 仍在跑但本轮 WHOIS 无事可做，让出 CPU 等待 RDAP socket。
      if (!$rdapDone && $whoisDone) {
        curl_multi_select($mh, 0.1);
      }
    }

    // 4) 收敛 RDAP 结果
    if ($mh !== null) {
      $response = curl_multi_getcontent($rdapHandle);
      if ($response === false || $response === null || $response === "") {
        $err = curl_error($rdapHandle);
        // 并行路径失败时退回串行 getData()（内含重试），最大化成功率
        curl_multi_remove_handle($mh, $rdapHandle);
        curl_close($rdapHandle);
        curl_multi_close($mh);
        $this->getRDAP();
      } else {
        [$code, $normalized] = $rdap->finalizeHandle($rdapHandle, $response);
        curl_multi_remove_handle($mh, $rdapHandle);
        curl_close($rdapHandle);
        curl_multi_close($mh);

        // 服务端瞬时错误：退回串行重试
        if (RDAP::isTransientCode($code)) {
          $this->getRDAP();
        } else {
          $this->ingestRdap($code, $normalized);
        }
      }
    }

    // 5) 收敛 WHOIS 结果
    if ($whois !== null) {
      if ($whoisWebFallback) {
        $this->getWHOIS(); // web 抓取走原串行路径
      } elseif ($whoisSocket !== null) {
        try {
          if ($whoisTimedOut) {
            throw new RuntimeException("查询超时，请稍后重试！");
          }
          $data = $whois->finalizeData($whoisData);
          $this->ingestWhois($whois, $data);
        } catch (Exception $e) {
          $this->captureWhoisUnknownOrError($e);
        }
      }
    }
  }

  // 将并行获取到的 RDAP 原始响应解析并落库（等价串行 getRDAP 的成功分支）。
  private function ingestRdap($code, $data)
  {
    try {
      $json = json_decode($data, true);
      if ($json) {
        $prettyData = json_encode(
          $json,
          JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $this->rdapData = preg_replace("/^(  +?)\\1(?=[^ ])/m", "$1", $prettyData);
      }
      $this->rdapParser = new ParserRDAP($code, $data, $json);
    } catch (Exception $e) {
      $this->captureRdapUnknownOrError($e);
    }
  }

  // 将并行获取到的 WHOIS 原始文本解析并落库（等价串行 getWHOIS 的成功分支）。
  private function ingestWhois($whois, $data)
  {
    $this->whoisData = $data;
    $this->whoisParser = ParserFactory::create($whois->extension, $data);
  }

  private function captureWhoisUnknownOrError(Exception $e)
  {
    if (
      $e->getMessage() === "No WHOIS server found for '$this->domain'" ||
      $e->getMessage() === "未找到 WHOIS 服务器 '$this->domain'"
    ) {
      $this->whoisUnknown = $e->getMessage();
    } else {
      $this->whoisError = $e->getMessage();
    }
  }

  private function captureRdapUnknownOrError(Exception $e)
  {
    if ($e->getMessage() === "No RDAP server found for '$this->domain'") {
      $this->rdapUnknown = $e->getMessage();
    } else {
      $this->rdapError = $e->getMessage();
    }
  }

  private function parseDomain($domain)
  {
    $publicSuffixList = Rules::fromPath(__DIR__ . "/data/public-suffix-list.dat");
    $domain = Domain::fromIDNA2008($domain);

    try {
      $domainName = $publicSuffixList->getPrivateDomain($domain);
      $this->domain = $domainName->registrableDomain()->toString();
      $this->extension = $domainName->suffix()->toString();
    } catch (Throwable $t) {
      try {
        $domainName = $publicSuffixList->getICANNDomain($domain);
        $this->domain = $domainName->registrableDomain()->toString();
        $this->extension = $domainName->suffix()->toString();
        $this->extensionTop = $domainName->domain()->label(0);
      } catch (Throwable $t) {
        if ($t->getMessage() === "The domain \"{$domain->toString()}\" can not contain a public suffix.") {
          $this->domain = $domain->toString();
          $this->extension = "iana";
        } else if (
          $t->getMessage() === "The public suffix and the domain name are identical `{$domain->toString()}`." &&
          count($domain->labels()) > 1
        ) {
          $this->domain = $domain->toString();
          $this->extension = $domain->label(0);
        } else {
          throw $t;
        }
      }
    }
  }

  private function getWHOIS()
  {
    try {
      $whois = new WHOIS($this->domain, $this->extension, $this->extensionTop);
      $data = $whois->getData();

      $this->whoisData = $data;

      $parser = ParserFactory::create($whois->extension, $data);
      if ($this->dataSource === ["whois"]) {
        $this->parser = $parser;

        if (!empty($this->parser->domain)) {
          $this->domain = $this->parser->domain;
        }
      } else {
        $this->whoisParser = $parser;
      }
    } catch (Exception $e) {
      if ($this->dataSource === ["whois"]) {
        throw $e;
      }

      if ($e->getMessage() === "No WHOIS server found for '$this->domain'") {
        $this->whoisUnknown = $e->getMessage();
      } else {
        $this->whoisError = $e->getMessage();
      }
    }
  }

  private function getRDAP()
  {
    try {
      $rdap = new RDAP($this->domain, $this->extension, $this->extensionTop);
      [$code, $data] = $rdap->getData();

      $json = json_decode($data, true);
      if ($json) {
        $prettyData = json_encode(
          $json,
          JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $this->rdapData = preg_replace("/^(  +?)\\1(?=[^ ])/m", "$1", $prettyData);
      }

      $parser = new ParserRDAP($code, $data, $json);
      if ($this->dataSource === ["rdap"]) {
        $this->parser = $parser;

        if (!empty($this->parser->domain)) {
          $this->domain = $this->parser->domain;
        }
      } else {
        $this->rdapParser = $parser;
      }
    } catch (Exception $e) {
      if ($this->dataSource === ["rdap"]) {
        throw $e;
      }

      if ($e->getMessage() === "No RDAP server found for '$this->domain'") {
        $this->rdapUnknown = $e->getMessage();
      } else {
        $this->rdapError = $e->getMessage();
      }
    }
  }

  private function merge()
  {
    if ($this->whoisUnknown && $this->rdapUnknown) {
      throw new RuntimeException("No WHOIS or RDAP server found for '$this->domain'");
    }

    if (($this->whoisError && $this->rdapUnknown) || ($this->whoisUnknown && $this->rdapError)) {
      throw new RuntimeException($this->whoisError ?: $this->rdapError);
    }

    if ($this->whoisError && $this->rdapError) {
      throw new RuntimeException("A temporary error has occurred");
    }

    if ($this->whoisParser && $this->rdapParser) {
      $this->mergeParser();
    } else if ($this->whoisParser) {
      $this->parser = $this->whoisParser;
    } else if ($this->rdapParser) {
      $this->parser = $this->rdapParser;
    } else {
      throw new RuntimeException("A temporary error has occurred");
    }
  }

  private function mergeParser()
  {
    $this->parser = $this->whoisParser;
    $this->parser->rdapData = $this->rdapParser->rdapData;

    if (!$this->rdapParser->registered) {
      return;
    }

    $properties = [
      "registered",
      "domain",
      "registrar",
      "registrarURL",
      "creationDate",
      "creationDateISO8601",
      "expirationDate",
      "expirationDateISO8601",
      "updatedDate",
      "updatedDateISO8601",
      "availableDate",
      "availableDateISO8601",
      "status",
      "nameServers",
      "dnssec",
      "age",
      "remaining",
      "gracePeriod",
      "redemptionPeriod",
      "pendingDelete",
    ];

    foreach ($properties as $property) {
      if (is_bool($this->rdapParser->$property) || $this->rdapParser->$property) {
        $this->parser->$property = $this->rdapParser->$property;
      }
    }

    // 扩展字段合并：RDAP 有值则覆盖 WHOIS（仅当 WHOIS 缺失或 RDAP 更完整时）。
    // 这些字段多为字符串，RDAP 优先能补全薄注册局 / RDAP-first 的 gTLD 缺失信息。
    $extendedProperties = [
      "registryDomainId",
      "whoisServer",
      "registrarIanaId",
      "registrarPhone",
      "registrarAddress",
      "reseller",
      "registrantName",
      "registrantOrg",
      "registrantCity",
      "registrantState",
      "registrantCountry",
      "registrantEmail",
      "registrantPhone",
      "adminName",
      "adminOrg",
      "adminEmail",
      "adminPhone",
      "adminCountry",
      "techName",
      "techOrg",
      "techEmail",
      "techPhone",
      "techCountry",
      "abuseEmail",
      "abusePhone",
      "dnsProvider",
      "dnsProviderUrl",
    ];

    foreach ($extendedProperties as $property) {
      if (empty($this->parser->$property) && !empty($this->rdapParser->$property)) {
        $this->parser->$property = $this->rdapParser->$property;
      }
    }

    foreach (["ageSeconds", "remainingSeconds"] as $property) {
      if ($this->rdapParser->$property !== null) {
        $this->parser->$property = $this->rdapParser->$property;
      }
    }

    $this->parser->unknown = $this->parser->getUnknown();
    if ($this->parser->unknown) {
      $this->parser->registered = false;
    }
  }
}
