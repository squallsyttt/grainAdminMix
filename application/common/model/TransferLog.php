<?php

namespace app\common\model;

use think\Model;

/**
 * 结算打款日志模型
 */
class TransferLog extends Model
{
    // 表名（不含 grain_ 前缀）
    protected $name = 'wanlshop_transfer_log';

    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
}

