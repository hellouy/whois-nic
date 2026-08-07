<?php

/**
 * 域名状态代码（EPP / gTLD / ccTLD）→ 简洁通俗的中文解释。
 *
 * 与 status-map.php（状态代码 → 简短中文名）配套使用：
 *   - status-map.php    负责"叫什么"（如 clientTransferProhibited → 禁止客户转移）
 *   - status-explain.php 负责"是什么意思"（一句通俗解释，面向普通用户）
 *
 * 键统一以小写匹配（模板侧会做大小写归一），值为一句话解释，避免专业术语。
 * 未命中时模板不显示解释行，因此这里只需覆盖常见状态即可。
 */

return [
    // ---- 正常 / 基础 ----
    'ok' => '域名状态正常，没有任何限制或待处理操作。',
    'active' => '域名处于活跃状态，正常解析与使用中。',
    'inactive' => '域名尚未配置名称服务器（NS），暂时无法解析。',
    'linked' => '域名已关联到注册人等对象，属正常状态。',
    'connect' => '域名已连接并正常解析。',

    // ---- 各类"禁止"锁定：向用户解释"锁了什么、通常为什么" ----
    'clienttransferprohibited' => '注册商已锁定域名，禁止转移到其它注册商，用于防止未经授权的转移（最常见的安全锁）。',
    'servertransferprohibited' => '注册局层面禁止域名转移，通常涉及争议、欠费或法律原因。',
    'clientupdateprohibited' => '注册商已锁定域名信息，禁止修改注册资料，防止被恶意篡改。',
    'serverupdateprohibited' => '注册局禁止修改域名信息，一般用于争议或安全冻结。',
    'clientdeleteprohibited' => '注册商已锁定域名，禁止被删除，避免误删或被盗删。',
    'serverdeleteprohibited' => '注册局禁止删除该域名，常见于争议或保护期。',
    'clientrenewprohibited' => '注册商禁止对该域名续费（较少见，可能涉及账户问题）。',
    'serverrenewprohibited' => '注册局禁止对该域名续费，通常处于特殊生命周期阶段。',
    'clienthold' => '注册商已暂停该域名解析，域名当前无法访问（常因未实名、欠费或违规）。',
    'serverhold' => '注册局已暂停该域名解析，域名当前无法访问，通常涉及合规或法律问题。',

    // ---- 生命周期 / 宽限期 ----
    'addperiod' => '域名刚注册不久，处于注册后的宽限期内。',
    'autorenewperiod' => '域名到期后进入自动续费宽限期，此期间仍可续费保留。',
    'renewperiod' => '域名处于续费后的宽限期。',
    'transferperiod' => '域名刚完成转移，处于转移后的宽限期。',
    'redemptionperiod' => '域名已过期并被删除，进入赎回期，原持有人仍可付费赎回，否则将被释放。',
    'pendingdelete' => '域名处于待删除阶段，赎回期已过，很快将被释放供公开注册。',
    'pendingrestore' => '域名正在从赎回期恢复中，等待恢复完成。',
    'pendingtransfer' => '域名正在转移到其它注册商，等待转移完成。',
    'pendingupdate' => '域名信息变更正在处理中。',
    'pendingrenew' => '域名续费请求正在处理中。',
    'pendingcreate' => '域名注册请求正在处理中。',
    'pendingdelete restorable' => '域名待删除但仍可恢复。',
    'graceperiod' => '域名处于操作后的宽限期内。',
    'restoreperiod' => '域名处于恢复期。',

    // ---- 状态词（无 client/server 前缀，多见于冷门 ccTLD）----
    'transferprohibited' => '该域名禁止转移。',
    'updateprohibited' => '该域名禁止修改注册信息。',
    'deleteprohibited' => '该域名禁止删除。',
    'renewprohibited' => '该域名禁止续费。',
    'locked' => '域名已被锁定，无法进行转移、修改等操作。',
    'unlocked' => '域名未锁定，可正常进行转移、修改等操作。',
    'frozen' => '域名已被冻结，暂停相关操作。',
    'blocked' => '域名已被封锁，无法正常使用。',
    'suspended' => '域名已被暂停，当前无法正常解析或使用。',
    'reserved' => '该域名被注册局保留，不开放公开注册。',
    'delegated' => '域名已委派到名称服务器，可正常解析。',
    'notdelegated' => '域名尚未委派名称服务器，暂时无法解析。',
    'expired' => '域名已过期，若不及时续费将进入赎回与删除流程。',
    'revoked' => '域名注册已被吊销。',

    // ---- 溢价 / 拍卖 / 特殊注册期 ----
    'premium' => '该域名为溢价域名，注册或续费价格通常高于普通域名。',
    'pendingauction' => '域名即将或正在进行拍卖。',
    'sunrise' => '处于商标持有人优先注册的"日出期"。',
    'landrush' => '处于公开开放前的"抢注期"。',
];
