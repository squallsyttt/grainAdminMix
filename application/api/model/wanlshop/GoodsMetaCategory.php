<?php

namespace app\api\model\wanlshop;

use think\Model;

class GoodsMetaCategory extends Model
{
    // 表名
    protected $name = 'wanlshop_goods_meta_category';

    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $deleteTime = false;
}

