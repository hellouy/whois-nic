<?php

/**
 * 全球域名注册商官网映射
 *
 * 覆盖亚洲、欧洲、美洲、非洲、大洋洲的大中小型注册商，
 * 用于在 WHOIS/RDAP 未提供 Registrar URL 时，根据注册商名称智能识别官网，
 * 方便用户点击直达对应注册商网站进行注册。
 *
 * 匹配策略：先做精确归一化匹配，再做关键词包含匹配（兼容 "GoDaddy.com, LLC" 等带后缀的写法）。
 */

/**
 * 归一化注册商名称：转小写、去除常见公司后缀与标点，便于匹配。
 */
function registrar_normalize(string $name): string
{
    $s = mb_strtolower(trim($name));
    // 去掉常见公司形式后缀
    $s = preg_replace('/\b(co\.,?\s*ltd\.?|ltd\.?|llc\.?|inc\.?|corp\.?|corporation|company|gmbh|s\.?a\.?s?\.?|s\.?r\.?l\.?|b\.?v\.?|pte\.?|pty\.?|limited|co\.)\b/u', '', $s);
    $s = str_replace(['有限公司', '股份有限公司', '科技', '集团', '（', '）'], '', $s);
    // 去标点与多余空白
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '', $s);
    return $s;
}

/**
 * 注册商关键词 => 官网。键使用小写关键词，匹配时用包含判断。
 * 顺序无关；较具体的关键词应尽量唯一。
 */
function registrar_keyword_map(): array
{
    return [
        // ===== 全球 / 北美 =====
        'godaddy' => 'https://www.godaddy.com',
        'namecheap' => 'https://www.namecheap.com',
        'networksolutions' => 'https://www.networksolutions.com',
        'network solutions' => 'https://www.networksolutions.com',
        'cloudflare' => 'https://www.cloudflare.com',
        'google' => 'https://domains.google',
        'squarespace' => 'https://domains.squarespace.com',
        'markmonitor' => 'https://www.markmonitor.com',
        'csc corporate' => 'https://www.cscdbs.com',
        'cscglobal' => 'https://www.cscdbs.com',
        'name.com' => 'https://www.name.com',
        'namecom' => 'https://www.name.com',
        'tucows' => 'https://www.tucows.com',
        'opensrs' => 'https://opensrs.com',
        'enom' => 'https://www.enom.com',
        'dynadot' => 'https://www.dynadot.com',
        'porkbun' => 'https://porkbun.com',
        'hover' => 'https://www.hover.com',
        'namesilo' => 'https://www.namesilo.com',
        'epik' => 'https://www.epik.com',
        'register.com' => 'https://www.register.com',
        'registercom' => 'https://www.register.com',
        'ionos' => 'https://www.ionos.com',
        '1&1' => 'https://www.ionos.com',
        'sav.com' => 'https://www.sav.com',
        'spaceship' => 'https://www.spaceship.com',
        'wix' => 'https://www.wix.com',
        'shopify' => 'https://www.shopify.com',
        'bluehost' => 'https://www.bluehost.com',
        'hostgator' => 'https://www.hostgator.com',
        'dreamhost' => 'https://www.dreamhost.com',
        'web.com' => 'https://www.web.com',
        'rebel' => 'https://www.rebel.com',
        'pananames' => 'https://www.pananames.com',
        'gname' => 'https://www.gname.com',
        'megalayer' => 'https://www.megalayer.com',
        'namebright' => 'https://www.namebright.com',

        // ===== 中国 =====
        'alibaba' => 'https://wanwang.aliyun.com',
        'aliyun' => 'https://wanwang.aliyun.com',
        'hichina' => 'https://wanwang.aliyun.com',
        '阿里' => 'https://wanwang.aliyun.com',
        '万网' => 'https://wanwang.aliyun.com',
        'tencent' => 'https://dnspod.cloud.tencent.com',
        'dnspod' => 'https://www.dnspod.cn',
        '腾讯' => 'https://dnspod.cloud.tencent.com',
        'west263' => 'https://www.west.cn',
        'west.cn' => 'https://www.west.cn',
        '西部数码' => 'https://www.west.cn',
        'xinnet' => 'https://www.xinnet.com',
        '新网' => 'https://www.xinnet.com',
        'ename' => 'https://www.ename.net',
        '易名' => 'https://www.ename.net',
        'huawei' => 'https://www.huaweicloud.com',
        '华为' => 'https://www.huaweicloud.com',
        'baidu' => 'https://cloud.baidu.com',
        '百度' => 'https://cloud.baidu.com',
        'bizcn' => 'https://www.bizcn.com',
        '商务中国' => 'https://www.bizcn.com',
        '22.cn' => 'https://www.22.cn',
        '爱名' => 'https://www.22.cn',
        'juming' => 'https://www.juming.com',
        '聚名' => 'https://www.juming.com',
        'nicenic' => 'https://www.nicenic.net',
        'zhcn' => 'https://www.zh-cn.com',
        '景安' => 'https://www.zhujiwu.com',
        'volcengine' => 'https://www.volcengine.com',
        '火山引擎' => 'https://www.volcengine.com',

        // ===== 日本 / 韩国 =====
        'gmo' => 'https://www.onamae.com',
        'onamae' => 'https://www.onamae.com',
        'お名前' => 'https://www.onamae.com',
        'sakura' => 'https://www.sakura.ad.jp',
        'value-domain' => 'https://www.value-domain.com',
        'value domain' => 'https://www.value-domain.com',
        'interlink' => 'https://muumuu-domain.com',
        'muumuu' => 'https://muumuu-domain.com',
        'gabia' => 'https://www.gabia.com',
        'hosting.kr' => 'https://www.hosting.kr',
        'whois.co.kr' => 'https://whois.co.kr',
        'inames' => 'https://www.inames.co.kr',

        // ===== 东南亚 / 南亚 =====
        'resellerclub' => 'https://www.resellerclub.com',
        'bigrock' => 'https://www.bigrock.in',
        'net4' => 'https://www.net4.in',
        'znetlive' => 'https://www.znetlive.com',
        'exabytes' => 'https://www.exabytes.com',
        'vodien' => 'https://www.vodien.com',
        'crazy domains' => 'https://www.crazydomains.com',
        'crazydomains' => 'https://www.crazydomains.com',

        // ===== 欧洲 =====
        'ovh' => 'https://www.ovhcloud.com',
        'gandi' => 'https://www.gandi.net',
        'hetzner' => 'https://www.hetzner.com',
        'united-domains' => 'https://www.united-domains.de',
        'united domains' => 'https://www.united-domains.de',
        'key-systems' => 'https://www.key-systems.net',
        'key systems' => 'https://www.key-systems.net',
        'internetx' => 'https://www.internetx.com',
        'eurodns' => 'https://www.eurodns.com',
        'openprovider' => 'https://www.openprovider.com',
        'hosting concepts' => 'https://www.openprovider.com',
        'realtime register' => 'https://www.realtimeregister.com',
        'realtimeregister' => 'https://www.realtimeregister.com',
        'ascio' => 'https://www.ascio.com',
        '123-reg' => 'https://www.123-reg.co.uk',
        '123 reg' => 'https://www.123-reg.co.uk',
        'fasthosts' => 'https://www.fasthosts.co.uk',
        'aruba' => 'https://www.aruba.it',
        'register.it' => 'https://www.register.it',
        'registerit' => 'https://www.register.it',
        'one.com' => 'https://www.one.com',
        'onecom' => 'https://www.one.com',
        'loopia' => 'https://www.loopia.com',
        'active 24' => 'https://www.active24.com',
        'active24' => 'https://www.active24.com',
        'namebay' => 'https://www.namebay.com',
        'netim' => 'https://www.netim.com',
        'infomaniak' => 'https://www.infomaniak.com',
        'strato' => 'https://www.strato.de',
        'domaindiscount24' => 'https://www.domaindiscount24.com',
        'hexonet' => 'https://www.hexonet.net',
        'cronon' => 'https://www.cronon.net',
        'nameshield' => 'https://www.nameshield.com',
        'safebrands' => 'https://www.safebrands.com',
        'mijndomein' => 'https://www.mijndomein.nl',
        'transip' => 'https://www.transip.nl',
        'combell' => 'https://www.combell.com',

        // ===== 非洲 =====
        'aos rwanda' => 'https://market.aos.rw',
        'web4africa' => 'https://www.web4africa.com',
        'truehost' => 'https://truehost.com',
        'hostafrica' => 'https://www.hostafrica.com',
        'domains.co.za' => 'https://domains.co.za',
        'afrihost' => 'https://www.afrihost.com',
        'sostec' => 'https://www.sostec.so',

        // ===== 美洲（拉美）=====
        'registro.br' => 'https://registro.br',
        'registrobr' => 'https://registro.br',
        'nic mexico' => 'https://www.nic.mx',
        'nic.mx' => 'https://www.nic.mx',
        'donweb' => 'https://donweb.com',
        'neubox' => 'https://www.neubox.com',

        // ===== 大洋洲 =====
        'netregistry' => 'https://www.netregistry.com.au',
        'ventraip' => 'https://ventraip.com.au',
        'synergy wholesale' => 'https://synergywholesale.com',
        'synergywholesale' => 'https://synergywholesale.com',

        // ============ 扩充：北美 / 全球 ============
        'domain.com' => 'https://www.domain.com',
        'register4less' => 'https://www.register4less.com',
        'moniker' => 'https://www.moniker.com',
        'uniregistry' => 'https://uniregistry.com',
        'fabulous' => 'https://www.fabulous.com',
        'directnic' => 'https://directnic.com',
        'pairdomains' => 'https://www.pairdomains.com',
        'hostinger' => 'https://www.hostinger.com',
        'cloudns' => 'https://www.cloudns.net',
        'wordpress' => 'https://wordpress.com/domains',
        'automattic' => 'https://wordpress.com/domains',
        '101domain' => 'https://www.101domain.com',
        'safenames' => 'https://www.safenames.net',
        'com laude' => 'https://comlaude.com',
        'comlaude' => 'https://comlaude.com',
        'brandsight' => 'https://www.brandsight.com',
        'tld registrar solutions' => 'https://tldregistrarsolutions.com',
        'sedo' => 'https://sedo.com',
        'dan.com' => 'https://dan.com',
        'verisign' => 'https://www.verisign.com',
        'cira' => 'https://www.cira.ca',

        // ============ 扩充：俄罗斯 / 独联体 ============
        'reg.ru' => 'https://www.reg.ru',
        'regru' => 'https://www.reg.ru',
        'ru-center' => 'https://www.nic.ru',
        'nic.ru' => 'https://www.nic.ru',
        'r01' => 'https://www.r01.ru',
        'beget' => 'https://beget.com',
        'timeweb' => 'https://timeweb.com',
        'webnames' => 'https://www.webnames.ru',
        'nic.ua' => 'https://nic.ua',
        'hostpro' => 'https://hostpro.ua',
        'imena' => 'https://www.imena.ua',

        // ============ 扩充：欧洲 ============
        'denic' => 'https://www.denic.de',
        'checkdomain' => 'https://www.checkdomain.de',
        'http.net' => 'https://www.http.net',
        'world4you' => 'https://www.world4you.com',
        'easyname' => 'https://www.easyname.com',
        'nic.at' => 'https://www.nic.at',
        'nominet' => 'https://www.nominet.uk',
        'afnic' => 'https://www.afnic.fr',
        'amen' => 'https://www.amen.fr',
        'lws' => 'https://www.lws.fr',
        'o2switch' => 'https://www.o2switch.fr',
        'planethoster' => 'https://www.planethoster.com',
        'sidn' => 'https://www.sidn.nl',
        'versio' => 'https://www.versio.nl',
        'antagonist' => 'https://www.antagonist.nl',
        'vimexx' => 'https://www.vimexx.nl',
        'hostnet' => 'https://www.hostnet.nl',
        'subreg' => 'https://subreg.cz',
        'forpsi' => 'https://www.forpsi.com',
        'wedos' => 'https://www.wedos.com',
        'hostpoint' => 'https://www.hostpoint.ch',
        'switchplus' => 'https://www.switchplus.ch',
        'nic.ch' => 'https://www.nic.ch',
        'domeneshop' => 'https://domene.shop',
        'norid' => 'https://www.norid.no',
        'iis' => 'https://internetstiftelsen.se',
        'nameisp' => 'https://www.nameisp.com',
        'dk-hostmaster' => 'https://www.dk-hostmaster.dk',
        'eurid' => 'https://eurid.eu',
        'dns belgium' => 'https://www.dnsbelgium.be',
        'home.pl' => 'https://home.pl',
        'nazwa' => 'https://www.nazwa.pl',
        'az.pl' => 'https://az.pl',
        'dondominio' => 'https://www.dondominio.com',
        'cdmon' => 'https://www.cdmon.com',
        'arsys' => 'https://www.arsys.es',
        'nominalia' => 'https://www.nominalia.com',
        'serverplan' => 'https://www.serverplan.com',
        'papaki' => 'https://www.papaki.com',
        'pointer' => 'https://www.pointer.gr',
        'natro' => 'https://www.natro.com',
        'isimtescil' => 'https://www.isimtescil.net',
        'turhost' => 'https://www.turhost.com',

        // ============ 扩充：中东 ============
        'aeserver' => 'https://aeserver.com',
        'etisalat' => 'https://www.etisalat.ae',
        'domain the net' => 'https://www.domainthenet.com',
        'livedns' => 'https://www.livedns.co.il',

        // ============ 扩充：东亚（中国） ============
        'cndns' => 'https://www.cndns.com',
        '美橙' => 'https://www.cndns.com',
        '35.com' => 'https://www.35.com',
        '三五互联' => 'https://www.35.com',
        'now.cn' => 'https://www.now.cn',
        '时代互联' => 'https://www.now.cn',
        'zzidc' => 'https://www.zzidc.com',
        'cnnic' => 'https://www.cnnic.net.cn',

        // ============ 扩充：东亚（日韩台港） ============
        'jprs' => 'https://jprs.jp',
        'value-domain.com' => 'https://www.value-domain.com',
        'krnic' => 'https://krnic.or.kr',
        'cafe24' => 'https://www.cafe24.com',
        'twnic' => 'https://www.twnic.tw',
        'net-chinese' => 'https://www.net-chinese.com.tw',
        'pchome' => 'https://myname.pchome.com.tw',
        'hkdnr' => 'https://www.hkdnr.hk',

        // ============ 扩充：东南亚 / 南亚 ============
        'niagahoster' => 'https://www.niagahoster.co.id',
        'rumahweb' => 'https://www.rumahweb.com',
        'idwebhost' => 'https://idwebhost.com',
        'qwords' => 'https://www.qwords.com',
        'pandi' => 'https://pandi.id',
        'shinjiru' => 'https://www.shinjiru.com.my',
        'pavietnam' => 'https://www.pavietnam.vn',
        'matbao' => 'https://www.matbao.net',
        'nhan hoa' => 'https://nhanhoa.com',
        'inet' => 'https://inet.vn',
        'thnic' => 'https://www.thnic.co.th',
        'milesweb' => 'https://www.milesweb.com',
        'hostingraja' => 'https://www.hostingraja.in',

        // ============ 扩充：非洲 ============
        'whogohost' => 'https://www.whogohost.com',
        'qservers' => 'https://www.qservers.net',
        'domainking' => 'https://www.domainking.ng',
        'kenya web experts' => 'https://www.kenyawebexperts.com',
        'hostpinnacle' => 'https://www.hostpinnacle.co.ke',
        'eac directory' => 'https://eacdirectory.co.ke',

        // ============ 扩充：拉丁美洲 ============
        'locaweb' => 'https://www.locaweb.com.br',
        'uol host' => 'https://www.uolhost.uol.com.br',
        'kinghost' => 'https://king.host',
        'nic.ar' => 'https://nic.ar',
        'akky' => 'https://www.akky.mx',
        'nic.cl' => 'https://www.nic.cl',
        'haulmer' => 'https://www.haulmer.com',
        'cointernet' => 'https://www.cointernet.com.co',
        'punto.pe' => 'https://punto.pe',

        // ============ 扩充：大洋洲 ============
        'digital pacific' => 'https://www.digitalpacific.com.au',
        'zuver' => 'https://www.zuver.net.au',
        'tpp wholesale' => 'https://www.tppwholesale.com.au',
        'melbourne it' => 'https://www.melbourneit.com.au',
        'auda' => 'https://www.auda.org.au',
        'freeparking' => 'https://www.freeparking.co.nz',
        '1st domains' => 'https://www.1stdomains.nz',
        'discount domains' => 'https://www.discountdomains.co.nz',
        'metaname' => 'https://www.metaname.net',

        // ============ 增强：北美 / 全球大型平台与批发商 ============
        'publicdomainregistry' => 'https://publicdomainregistry.com',
        'pdr ltd' => 'https://publicdomainregistry.com',
        'endurance' => 'https://newfold.com',
        'newfold' => 'https://newfold.com',
        'wild west domains' => 'https://www.wildwestdomains.com',
        'secureserver' => 'https://www.godaddy.com',
        'amazon registrar' => 'https://registrar.amazon.com',
        'route 53' => 'https://aws.amazon.com/route53',
        'onlinenic' => 'https://www.onlinenic.com',
        'eranet' => 'https://www.eranet.com',
        'instra' => 'https://www.instra.com',
        'marcaria' => 'https://www.marcaria.com',
        'internet.bs' => 'https://internetbs.net',
        'internetbs' => 'https://internetbs.net',
        'encirca' => 'https://www.encirca.com',
        'gkg.net' => 'https://www.gkg.net',
        'cosmotown' => 'https://www.cosmotown.com',
        '1api' => 'https://www.1api.net',
        'brandit' => 'https://www.brandit.com',
        'nameserver.com' => 'https://www.nameserver.com',
        'siteground' => 'https://www.siteground.com',
        'a2 hosting' => 'https://www.a2hosting.com',
        'a2hosting' => 'https://www.a2hosting.com',
        'inmotion' => 'https://www.inmotionhosting.com',
        'greengeeks' => 'https://www.greengeeks.com',
        'namecheap.com' => 'https://www.namecheap.com',
        'onlydomains' => 'https://www.onlydomains.com',

        // ============ 增强：加拿大 ============
        'easydns' => 'https://easydns.com',
        'domainpeople' => 'https://www.domainpeople.com',
        'namespro' => 'https://www.namespro.ca',
        'webnames.ca' => 'https://www.webnames.ca',
        'hostpapa' => 'https://www.hostpapa.com',
        'internic.ca' => 'https://www.cira.ca',

        // ============ 增强：英国 / 爱尔兰 ============
        'inwx' => 'https://www.inwx.com',
        'netistrar' => 'https://www.netistrar.com',
        '20i' => 'https://www.20i.com',
        'heart internet' => 'https://www.heartinternet.uk',
        'names.co.uk' => 'https://www.names.co.uk',
        'uk2' => 'https://www.uk2.net',
        'tsohost' => 'https://www.tsohost.com',
        'domainmonster' => 'https://www.domainmonster.com',
        'blacknight' => 'https://www.blacknight.com',
        'register365' => 'https://www.register365.com',

        // ============ 增强：德语区 ============
        'netcup' => 'https://www.netcup.de',
        'domainfactory' => 'https://www.df.eu',
        'df.eu' => 'https://www.df.eu',
        'host europe' => 'https://www.hosteurope.de',
        'hosteurope' => 'https://www.hosteurope.de',
        'all-inkl' => 'https://all-inkl.com',
        'manitu' => 'https://www.manitu.de',
        'variomedia' => 'https://www.variomedia.de',
        'united internet' => 'https://www.ionos.de',
        'hosttech' => 'https://www.hosttech.eu',
        'metanet' => 'https://www.metanet.ch',
        'infomaniak.ch' => 'https://www.infomaniak.com',

        // ============ 增强：法国 ============
        'ikoula' => 'https://www.ikoula.com',
        'scaleway' => 'https://www.scaleway.com',
        'online.net' => 'https://www.scaleway.com',
        'bookmyname' => 'https://www.bookmyname.com',
        'ovh.com' => 'https://www.ovhcloud.com',
        'hostinger.fr' => 'https://www.hostinger.fr',

        // ============ 增强：南欧（意/西/葡） ============
        'netsons' => 'https://www.netsons.com',
        'keliweb' => 'https://www.keliweb.it',
        'tophost' => 'https://www.tophost.it',
        'seeweb' => 'https://www.seeweb.it',
        'raiola networks' => 'https://raiolanetworks.es',
        'webempresa' => 'https://www.webempresa.com',
        'hostalia' => 'https://www.hostalia.com',
        'gigas' => 'https://gigas.com',
        'dinahosting' => 'https://dinahosting.com',
        'ptisp' => 'https://www.ptisp.pt',
        'amen.pt' => 'https://www.amen.pt',

        // ============ 增强：北欧 ============
        'binero' => 'https://www.binero.se',
        'oderland' => 'https://www.oderland.se',
        'gratisdns' => 'https://gratisdns.dk',
        'ports group' => 'https://portsgroup.com',
        'abion' => 'https://www.abion.com',
        'curanet' => 'https://curanet.dk',

        // ============ 增强：中东欧 / 波兰 / 捷克 ============
        'cyberfolks' => 'https://cyberfolks.pl',
        'zenbox' => 'https://zenbox.pl',
        'dhosting' => 'https://dhosting.pl',
        'ovh.pl' => 'https://www.ovhcloud.com/pl',
        'webglobe' => 'https://www.webglobe.sk',
        'gransy' => 'https://subreg.cz',
        'ignum' => 'https://www.ignum.cz',
        'websupport' => 'https://www.websupport.sk',
        'active24.hu' => 'https://www.active24.hu',
        'rackhost' => 'https://www.rackhost.hu',
        'mchost' => 'https://mchost.ru',
        'sprinthost' => 'https://sprinthost.ru',
        'jino' => 'https://jino.ru',
        'fozzy' => 'https://fozzy.com',
        'ukraine.com.ua' => 'https://www.ukraine.com.ua',
        'regery' => 'https://regery.com',
        '2domains' => 'https://2domains.ru',
        'domenus' => 'https://domenus.ru',

        // ============ 增强：土耳其 / 希腊 ============
        'ihs telekom' => 'https://www.ihs.com.tr',
        'guzel hosting' => 'https://www.guzel.net.tr',
        'radore' => 'https://www.radore.com',
        'top.host' => 'https://top.host',
        'dnhost' => 'https://dnhost.gr',

        // ============ 增强：中东 ============
        'hostsailor' => 'https://www.hostsailor.com',
        'srsplus' => 'https://www.srsplus.com',
        'netvision' => 'https://www.013net.net',
        'domainthenet' => 'https://www.domainthenet.com',

        // ============ 增强：中国大陆 ============
        '4.cn' => 'https://www.4.cn',
        'dnsla' => 'https://www.dns.la',
        'quyu' => 'https://www.quyu.net',
        '中资源' => 'https://www.zzy.cn',
        'zzy.cn' => 'https://www.zzy.cn',
        'idcps' => 'https://www.idcps.com',
        '72e' => 'https://www.72e.net',

        // ============ 增强：日本 ============
        'conoha' => 'https://www.conoha.jp',
        'star-domain' => 'https://www.star-domain.jp',
        'netowl' => 'https://www.netowl.jp',
        'xserver' => 'https://www.xserver.ne.jp',
        'xdomain' => 'https://www.xdomain.ne.jp',
        'lolipop' => 'https://lolipop.jp',
        'kagoya' => 'https://www.kagoya.jp',
        'wadax' => 'https://www.wadax.ne.jp',

        // ============ 增强：韩国 ============
        'asadal' => 'https://www.asadal.com',
        'mireene' => 'https://www.mireene.com',
        'megazone' => 'https://www.hosting.kr',
        'dotname' => 'https://www.dotname.co.kr',
        'i2i' => 'https://www.i2i.co.kr',

        // ============ 增强：台湾 / 香港 ============
        'seednet' => 'https://domain.seed.net.tw',
        'hinet' => 'https://domain.hinet.net',
        'so-net' => 'https://domain.so-net.net.tw',
        'newweb' => 'https://www.newweb.hk',

        // ============ 增强：东南亚 ============
        'webnic' => 'https://www.webnic.cc',
        'ipserverone' => 'https://www.ipserverone.com',
        'serverfreak' => 'https://www.serverfreak.com',
        'shinjiru.com.my' => 'https://www.shinjiru.com.my',
        'z.com' => 'https://www.z.com',
        'vinahost' => 'https://www.vinahost.vn',
        'tenten' => 'https://tenten.vn',
        'bkns' => 'https://www.bkns.vn',
        'digistar' => 'https://digistar.vn',
        'nhanhoa' => 'https://nhanhoa.com',
        'vodien.com' => 'https://www.vodien.com',
        'signetique' => 'https://www.signetique.com',

        // ============ 增强：南亚（印度 / 孟加拉 / 巴基斯坦 / 斯里兰卡） ============
        'hostkarle' => 'https://hostkarle.in',
        'hostinger.in' => 'https://www.hostinger.in',
        'bodhost' => 'https://www.bodhost.com',
        'exabytes.my' => 'https://www.exabytes.my',
        'itnut' => 'https://itnut.com.bd',
        'exonhost' => 'https://exonhost.com',
        'hostever' => 'https://hostever.com',
        'navicosoft' => 'https://www.navicosoft.com',

        // ============ 增强：非洲 ============
        'webafrica' => 'https://www.webafrica.com',
        '1-grid' => 'https://www.1-grid.com',
        'onegrid'  => 'https://www.1-grid.com',
        'diadem' => 'https://www.diadem.co.za',
        'gridhost' => 'https://www.gridhost.co.za',
        'axxess' => 'https://www.axxess.co.za',
        'smartweb' => 'https://www.smartweb.com.ng',
        'garanntor' => 'https://www.garanntor.com',
        'upperlink' => 'https://www.upperlink.ng',
        'cyberspace' => 'https://www.cyberspace.net.ng',
        'menara' => 'https://www.menara.ma',
        'genious' => 'https://www.genious.net',
        'egypt network' => 'https://www.egyptnetwork.com',
        'safaricom' => 'https://www.safaricom.co.ke',
        'sasahost' => 'https://sasahost.co.ke',

        // ============ 增强：拉丁美洲 ============
        'hostgator.com.br' => 'https://www.hostgator.com.br',
        'hostnet.com.br' => 'https://www.hostnet.com.br',
        'valuehost' => 'https://www.valuehost.com.br',
        'hostmidia' => 'https://www.hostmidia.com.br',
        'redehost' => 'https://www.redehost.com.br',
        'colombia hosting' => 'https://www.colombiahosting.com.co',
        'colombiahosting' => 'https://www.colombiahosting.com.co',
        'ferozo' => 'https://www.ferozo.com',
        'nic.pe' => 'https://punto.pe',
        'nic.co' => 'https://www.cointernet.com.co',
        'hostname.cl' => 'https://www.hostname.cl',
        'planetahosting' => 'https://www.planetahosting.com.ar',
        'antel' => 'https://www.antel.com.uy',
        'dattatec' => 'https://www.dattatec.com',

        // ============ 增强：大洋洲 ============
        'webcentral' => 'https://www.webcentral.au',
        'dreamscape networks' => 'https://www.dreamscapenetworks.com',
        'conetix' => 'https://www.conetix.com.au',
        'micron21' => 'https://www.micron21.com',
        'panthur' => 'https://www.panthur.com.au',
        'ventraip.com.au' => 'https://ventraip.com.au',
        'umbrellar' => 'https://umbrellar.com',
        'sitehost' => 'https://www.sitehost.nz',
        'iwantmyname' => 'https://iwantmyname.com',
        'voyager' => 'https://www.voyager.nz',

        // ============ 增强：注册局 / 品牌保护 / 平台 ============
        'identity digital' => 'https://www.identity.digital',
        'donuts' => 'https://www.identity.digital',
        'afilias' => 'https://www.identity.digital',
        'centralnic' => 'https://www.centralnic.com',
        'radix' => 'https://radix.website',
        'neustar' => 'https://www.neustar.biz',
        'google registry' => 'https://www.registry.google',
        'nic.io'  => 'https://nic.io',
        'freenom' => 'https://www.freenom.com',
        'gname.com' => 'https://www.gname.com',
        'key-systems.net' => 'https://www.key-systems.net',
        'rrpproxy' => 'https://www.rrpproxy.net',

        // ============ 增强补全：常见但此前缺失的注册商 ============
        'spaceship' => 'https://www.spaceship.com',
        'porkbun' => 'https://porkbun.com',
        'dynadot' => 'https://www.dynadot.com',
        'namesilo' => 'https://www.namesilo.com',
        'sav.com' => 'https://www.sav.com',
        'sav,' => 'https://www.sav.com',
        'hostinger' => 'https://www.hostinger.com',
        'ovh' => 'https://www.ovhcloud.com',
        'gandi' => 'https://www.gandi.net',
        'ionos' => 'https://www.ionos.com',
        '1&1' => 'https://www.ionos.com',
        'enom' => 'https://www.enom.com',
        'tucows' => 'https://tucows.com',
        'wix' => 'https://www.wix.com',
        'squarespace' => 'https://domains.squarespace.com',
        'automattic' => 'https://wordpress.com',
        'wordpress' => 'https://wordpress.com',
        'markmonitor' => 'https://www.markmonitor.com',
        'csc corporate domains' => 'https://www.cscdbs.com',
        'com laude' => 'https://comlaude.com',
        'nom-iq' => 'https://comlaude.com',
        'safenames' => 'https://www.safenames.net',
        'ascio' => 'https://www.ascio.com',
        'epik' => 'https://www.epik.com',
        'name.com' => 'https://www.name.com',
        'hover' => 'https://www.hover.com',
        'namebright' => 'https://www.namebright.com',
        'openprovider' => 'https://www.openprovider.com',
        'realtime register' => 'https://www.realtimeregister.com',
        'megazone' => 'https://www.hosting.kr',
        'hosting.kr' => 'https://www.hosting.kr',
        'gabia' => 'https://www.gabia.com',
        'whois corp' => 'https://whois.co.kr',
        'onamae' => 'https://www.onamae.com',
        'お名前' => 'https://www.onamae.com',
        'sakura' => 'https://www.sakura.ad.jp',
        'value-domain' => 'https://www.value-domain.com',
        'hostgator' => 'https://www.hostgator.com',
        'bluehost' => 'https://www.bluehost.com',
        'dreamhost' => 'https://www.dreamhost.com',
        'namebright.com' => 'https://www.namebright.com',
    ];
}

/**
 * 清洗注册商名称，用于展示与识别。
 *
 * WHOIS/RDAP 常返回隐私占位符或无意义值（如 "REDACTED FOR PRIVACY"、
 * "Not Disclosed"、"N/A"、"-"）。这类值不应作为注册商名展示，也不利于官网识别。
 * 本函数：去首尾引号/空白、折叠空白；当归一化后命中"垃圾占位值"时返回空串，
 * 以便上层回退到 RDAP 结构化字段或直接隐藏，避免展示误导性内容。
 *
 * 仅做精确（整串）匹配，绝不做子串匹配，避免误伤含这些词的真实注册商名。
 */
function registrar_clean(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }

    // 去掉包裹的引号
    $name = trim($name, "\"'");
    // 折叠内部连续空白
    $name = preg_replace('/\s+/u', ' ', $name);
    $name = trim($name);
    if ($name === '') {
        return '';
    }

    // 归一化后与"垃圾占位值"做整串比较
    $norm = mb_strtolower($name);
    $norm = preg_replace('/[^\p{L}\p{N}]+/u', '', $norm); // 仅留字母数字

    static $junk = null;
    if ($junk === null) {
        $raw = [
            'redactedforprivacy', 'redacted', 'dataredacted', 'redactedforgdpr',
            'redactedwhois', 'notdisclosed', 'notdisclosed!', 'notavailable',
            'notapplicable', 'na', 'nan', 'none', 'null', 'nil', 'unknown',
            'private', 'privacyprotected', 'whoisprivacy', 'privacyprotect',
            'statutorymaskingenabled', 'gdprmasked', 'gdpr', 'masked',
            'nodata', 'withheldforprivacy', 'domainprivacy', 'privacyservice',
            'nonepublic', 'confidential', 'hidden', 'protected',
        ];
        $junk = array_fill_keys($raw, true);
    }

    if ($norm === '' || isset($junk[$norm])) {
        return '';
    }

    return $name;
}

/**
 * 根据注册商名称查找其官网。找不到返回空字符串。
 */
function registrar_website(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }

    $map = registrar_keyword_map();
    $normName = registrar_normalize($name);
    $lower = mb_strtolower($name);

    // 1) 归一化精确匹配
    foreach ($map as $kw => $url) {
        if (registrar_normalize($kw) === $normName) {
            return $url;
        }
    }

    // 2) 关键词包含匹配（原始小写串包含关键词，或归一化串互相包含）
    foreach ($map as $kw => $url) {
        $kwLower = mb_strtolower($kw);
        $kwNorm = registrar_normalize($kw);
        if ($kwNorm === '') {
            continue;
        }
        if (strpos($lower, $kwLower) !== false || ($kwNorm !== '' && strpos($normName, $kwNorm) !== false)) {
            return $url;
        }
    }

    return '';
}
